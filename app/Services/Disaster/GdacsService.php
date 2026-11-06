<?php

namespace App\Services\Disaster;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class GdacsService
{
    private const CACHE_KEY = 'gdacs_disaster_events_sea';
    private const CACHE_META_KEY = 'gdacs_meta_sea';
    private const CACHE_TTL_SECONDS = 300; // 5 minutes

    // ASEAN 11 ISO3 Codes
    private const ASEAN_ISO3 = [
        'BRN', 'KHM', 'IDN', 'LAO', 'MYS', 'MMR', 'PHL', 'SGP', 'THA', 'TLS', 'VNM'
    ];

    private const ASEAN_NAMES = [
        'brunei' => 'Brunei',
        'cambodia' => 'Cambodia',
        'indonesia' => 'Indonesia',
        'laos' => 'Laos',
        'lao' => 'Laos',
        'malaysia' => 'Malaysia',
        'myanmar' => 'Myanmar',
        'burma' => 'Myanmar',
        'philippines' => 'Philippines',
        'singapore' => 'Singapore',
        'thailand' => 'Thailand',
        'timor-leste' => 'Timor-Leste',
        'east timor' => 'Timor-Leste',
        'vietnam' => 'Vietnam',
        'viet nam' => 'Vietnam',
    ];

    /**
     * Get real GDACS disaster events filtered to Southeast Asia.
     *
     * @return array{events: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function getEvents(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        $cachedMeta = Cache::get(self::CACHE_META_KEY);

        // If fresh cache exists, return it
        if ($cached !== null && $cachedMeta !== null && ($cachedMeta['is_fresh'] ?? false)) {
            return [
                'events' => $cached,
                'meta' => $cachedMeta,
            ];
        }

        try {
            // GDACS Search API
            $url = 'https://www.gdacs.org/gdacsapi/api/events/geteventlist/SEARCH';
            $response = Http::withoutVerifying()
                ->timeout(6)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'DisasterLinkSEA/1.0',
                ])
                ->get($url, [
                    'eventtypes' => 'EQ,TC,FL,VO,WF,DR',
                    'alertlevel' => 'Green;Orange;Red',
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $features = $data['features'] ?? [];
                $normalizedEvents = $this->processFeatures($features);

                $latestEventTime = null;
                foreach ($normalizedEvents as $ev) {
                    if ($latestEventTime === null || $ev['event_time'] > $latestEventTime) {
                        $latestEventTime = $ev['event_time'];
                    }
                }

                $meta = [
                    'source' => 'GDACS',
                    'name' => 'Global Disaster Alert and Coordination System',
                    'status' => 'Live',
                    'last_successful_fetch' => Carbon::now()->toIso8601String(),
                    'latest_event_time' => $latestEventTime,
                    'total_features_fetched' => count($features),
                    'sea_events_count' => count($normalizedEvents),
                    'is_fresh' => true,
                    'is_stale' => false,
                    'documentation_url' => 'https://www.gdacs.org/gdacsapi/swagger/index.html',
                ];

                Cache::put(self::CACHE_KEY, $normalizedEvents, self::CACHE_TTL_SECONDS);
                Cache::put(self::CACHE_META_KEY, $meta, self::CACHE_TTL_SECONDS);

                return [
                    'events' => $normalizedEvents,
                    'meta' => $meta,
                ];
            }

            Log::warning('GDACS API returned non-200 status: ' . $response->status());
        } catch (Throwable $e) {
            Log::error('GDACS API fetch error: ' . $e->getMessage());
        }

        // Return stale cache if available
        if ($cached !== null && $cachedMeta !== null) {
            $cachedMeta['status'] = 'Stale';
            $cachedMeta['is_stale'] = true;
            $cachedMeta['is_fresh'] = false;
            $cachedMeta['error'] = 'Live refresh failed; serving cached data.';
            return [
                'events' => $cached,
                'meta' => $cachedMeta,
            ];
        }

        // Completely unavailable fallback
        return [
            'events' => [],
            'meta' => [
                'source' => 'GDACS',
                'name' => 'Global Disaster Alert and Coordination System',
                'status' => 'Unavailable',
                'last_successful_fetch' => null,
                'latest_event_time' => null,
                'total_features_fetched' => 0,
                'sea_events_count' => 0,
                'is_fresh' => false,
                'is_stale' => false,
                'error' => 'Unable to contact GDACS server.',
                'documentation_url' => 'https://www.gdacs.org/gdacsapi/swagger/index.html',
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $features
     * @return array<int, array<string, mixed>>
     */
    private function processFeatures(array $features): array
    {
        $events = [];

        foreach ($features as $feature) {
            $props = $feature['properties'] ?? [];
            $geom = $feature['geometry'] ?? [];
            $coords = $geom['coordinates'] ?? null;

            $lat = null;
            $lng = null;
            if (is_array($coords) && count($coords) >= 2) {
                // GeoJSON format is [lng, lat]
                $lng = (float) $coords[0];
                $lat = (float) $coords[1];
            }

            $iso3 = strtoupper(trim((string) ($props['iso3'] ?? '')));
            $countryName = trim((string) ($props['country'] ?? ''));
            $affectedCountries = $props['affectedcountries'] ?? [];

            // Check if this event belongs to or affects Southeast Asia
            $matchedCountry = $this->matchAseanCountry($iso3, $countryName, $affectedCountries, $lat, $lng);
            if ($matchedCountry === null) {
                continue;
            }

            $eventType = strtoupper(trim((string) ($props['eventtype'] ?? '')));
            $category = $this->mapEventTypeToCategory($eventType);

            $eventId = (string) ($props['eventid'] ?? '');
            $episodeId = (string) ($props['episodeid'] ?? '');
            $id = 'gdacs_' . strtolower($eventType) . '_' . ($eventId ?: uniqid());

            // Exact official GDACS alert level: Green, Orange, Red
            $alertLevel = ucfirst(strtolower(trim((string) ($props['alertlevel'] ?? ''))));
            if (!in_array($alertLevel, ['Green', 'Orange', 'Red'])) {
                $alertLevel = null;
            }

            // Severity details
            $severityData = $props['severitydata'] ?? [];
            $severityVal = isset($severityData['severity']) ? (float) $severityData['severity'] : null;
            $severityText = $severityData['severitytext'] ?? ($props['htmldescription'] ?? null);

            // Time parsing
            $fromDate = $props['fromdate'] ?? null;
            $dateModified = $props['datemodified'] ?? null;
            $eventTime = null;
            if (!empty($fromDate)) {
                try {
                    $eventTime = Carbon::parse($fromDate)->toIso8601String();
                } catch (Throwable) {
                    $eventTime = $fromDate;
                }
            }

            // Source URL
            $urlProps = $props['url'] ?? [];
            $reportUrl = is_array($urlProps) ? ($urlProps['report'] ?? null) : null;
            if (empty($reportUrl) && !empty($eventId)) {
                $reportUrl = "https://www.gdacs.org/report.aspx?eventid={$eventId}&episodeid={$episodeId}&eventtype={$eventType}";
            }

            $title = trim((string) ($props['name'] ?? $props['eventname'] ?? ($category . ' in ' . $matchedCountry)));
            $description = trim((string) ($props['description'] ?? $props['htmldescription'] ?? ''));

            $events[] = [
                'id' => $id,
                'source' => 'GDACS',
                'source_id' => $eventId,
                'category' => $category,
                'raw_type' => $eventType,
                'title' => $title,
                'description' => strip_tags($description),
                'country' => $matchedCountry,
                'iso3' => $iso3 ?: null,
                'latitude' => $lat,
                'longitude' => $lng,
                'coordinates' => ($lat !== null && $lng !== null) ? [$lat, $lng] : null,
                'severity_level' => $alertLevel,
                'severity_score' => $severityVal,
                'severity_text' => $severityText ? strip_tags((string) $severityText) : ($alertLevel ? "{$alertLevel} Alert" : null),
                'depth_km' => ($eventType === 'EQ' && isset($severityData['severityunit']) && str_contains(strtolower($severityText ?? ''), 'depth:'))
                    ? $this->extractDepthFromText((string) $severityText)
                    : null,
                'event_time' => $eventTime,
                'source_url' => $reportUrl ?: 'https://www.gdacs.org',
            ];
        }

        return $events;
    }

    /**
     * @param array<int, array<string, mixed>> $affectedCountries
     */
    private function matchAseanCountry(string $iso3, string $countryName, array $affectedCountries, ?float $lat, ?float $lng): ?string
    {
        // 1. Direct ISO3 match
        if (in_array($iso3, self::ASEAN_ISO3)) {
            return self::ASEAN_NAMES[strtolower($countryName)] ?? $countryName;
        }

        // 2. Direct name match
        $lowerCountry = strtolower($countryName);
        if (isset(self::ASEAN_NAMES[$lowerCountry])) {
            return self::ASEAN_NAMES[$lowerCountry];
        }

        // 3. Affected countries list
        foreach ($affectedCountries as $ac) {
            $acIso3 = strtoupper(trim((string) ($ac['iso3'] ?? '')));
            $acName = strtolower(trim((string) ($ac['countryname'] ?? '')));
            if (in_array($acIso3, self::ASEAN_ISO3)) {
                return self::ASEAN_NAMES[$acName] ?? ($ac['countryname'] ?? 'Southeast Asia');
            }
            if (isset(self::ASEAN_NAMES[$acName])) {
                return self::ASEAN_NAMES[$acName];
            }
        }

        // 4. Coordinate bounding box: Lat -11.5 to 28.5, Lng 92.0 to 142.0
        if ($lat !== null && $lng !== null) {
            if ($lat >= -11.5 && $lat <= 28.5 && $lng >= 92.0 && $lng <= 142.0) {
                // Return country name if available or Southeast Asia
                return !empty($countryName) ? $countryName : 'Southeast Asia';
            }
        }

        return null;
    }

    private function mapEventTypeToCategory(string $eventType): string
    {
        return match ($eventType) {
            'EQ' => 'Earthquake',
            'TC' => 'Tropical Cyclone',
            'FL' => 'Flood',
            'VO' => 'Volcano',
            'WF' => 'Wildfire',
            'DR' => 'Drought',
            default => 'Other Disaster',
        };
    }

    private function extractDepthFromText(string $text): ?float
    {
        if (preg_match('/Depth:\s*([0-9.]+)\s*km/i', $text, $matches)) {
            return (float) $matches[1];
        }
        return null;
    }
}
