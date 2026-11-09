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
    private const CACHE_FRESH_KEY = 'gdacs_fresh_lock_sea';
    private const DEFAULT_TTL_SECONDS = 300; // 5 minutes
    private const STALE_BACKUP_TTL_SECONDS = 86400; // 24 hours backup for stale fallback
    private const MIN_FORCE_REFRESH_INTERVAL = 15; // seconds

    // ASEAN 11 ISO3 Codes
    private const ASEAN_ISO3 = [
        'BRN', 'KHM', 'IDN', 'LAO', 'MYS', 'MMR', 'PHL', 'SGP', 'THA', 'TLS', 'VNM',
    ];

    private const COUNTRY_TO_ISO3 = [
        'Brunei' => 'BRN',
        'Cambodia' => 'KHM',
        'Indonesia' => 'IDN',
        'Laos' => 'LAO',
        'Malaysia' => 'MYS',
        'Myanmar' => 'MMR',
        'Philippines' => 'PHL',
        'Singapore' => 'SGP',
        'Thailand' => 'THA',
        'Timor-Leste' => 'TLS',
        'Vietnam' => 'VNM',
    ];

    private const ASEAN_NAMES = [
        'brunei' => 'Brunei',
        'brunei darussalam' => 'Brunei',
        'cambodia' => 'Cambodia',
        'indonesia' => 'Indonesia',
        'laos' => 'Laos',
        'lao' => 'Laos',
        'lao pdr' => 'Laos',
        "lao people's democratic republic" => 'Laos',
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
    public function getEvents(bool $forceRefresh = false): array
    {
        $ttl = (int) config('services.disaster_apis.gdacs.cache_ttl', self::DEFAULT_TTL_SECONDS);
        $staleTtl = (int) config('services.disaster_apis.stale_ttl', self::STALE_BACKUP_TTL_SECONDS);
        $timeout = (int) config('services.disaster_apis.timeout', 8);
        $url = (string) config(
            'services.disaster_apis.gdacs.url',
            'https://www.gdacs.org/gdacsapi/api/events/geteventlist/SEARCH',
        );

        $cached = Cache::get(self::CACHE_KEY);
        $cachedMeta = Cache::get(self::CACHE_META_KEY);
        $isFreshLock = Cache::has(self::CACHE_FRESH_KEY);

        if ($cached !== null && $cachedMeta !== null) {
            if (!$forceRefresh && $isFreshLock) {
                return [
                    'events' => $cached,
                    'meta' => $this->refreshMetaTimestamps($cachedMeta, $ttl, true),
                ];
            }

            // Prevent upstream rate-limit abuse on rapid manual refreshes
            if ($forceRefresh && !empty($cachedMeta['last_successful_fetch'])) {
                try {
                    $secondsSinceFetch = abs(Carbon::now()->diffInSeconds(Carbon::parse($cachedMeta['last_successful_fetch'])));
                    if ($secondsSinceFetch < self::MIN_FORCE_REFRESH_INTERVAL && $isFreshLock) {
                        return [
                            'events' => $cached,
                            'meta' => $this->refreshMetaTimestamps($cachedMeta, $ttl, true),
                        ];
                    }
                } catch (Throwable) {
                    // Continue with live fetch
                }
            }
        }

        $errorReason = 'Unable to contact GDACS server.';
        $connectionStatus = 'Connection Failed';

        try {
            $response = Http::withoutVerifying()
                ->timeout($timeout)
                ->connectTimeout(5)
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

                // Note: GDACS returns HTTP 204 No Content or empty body when no matching events exist
                if ($response->status() === 204 || $data === null) {
                    $features = [];
                } elseif (is_array($data) && isset($data['features']) && is_array($data['features'])) {
                    $features = $data['features'];
                } else {
                    throw new \RuntimeException('Invalid GeoJSON structure returned by GDACS API.');
                }

                $normalizedEvents = $this->processFeatures($features);

                $latestEventTime = null;
                foreach ($normalizedEvents as $ev) {
                    if (!empty($ev['event_time']) && ($latestEventTime === null || $ev['event_time'] > $latestEventTime)) {
                        $latestEventTime = $ev['event_time'];
                    }
                }

                $nowIso = Carbon::now()->toIso8601String();
                $meta = [
                    'source' => 'GDACS',
                    'name' => 'Global Disaster Alert and Coordination System (UN / EC JRC)',
                    'status' => 'Live',
                    'freshness_status' => 'Fresh',
                    'connection_status' => 'Connected (HTTP ' . $response->status() . ')',
                    'last_successful_fetch' => $nowIso,
                    'latest_event_time' => $latestEventTime,
                    'total_features_fetched' => count($features),
                    'sea_events_count' => count($normalizedEvents),
                    'cache_ttl_seconds' => $ttl,
                    'provider_update_frequency' => 'Every 5–15 minutes (event-driven bulletins)',
                    'frontend_poll_interval_seconds' => 60,
                    'is_fresh' => true,
                    'is_stale' => false,
                    'error' => null,
                    'documentation_url' => 'https://www.gdacs.org/gdacsapi/swagger/index.html',
                ];

                Cache::put(self::CACHE_FRESH_KEY, true, $ttl);
                Cache::put(self::CACHE_KEY, $normalizedEvents, $staleTtl);
                Cache::put(self::CACHE_META_KEY, $meta, $staleTtl);

                return [
                    'events' => $normalizedEvents,
                    'meta' => $meta,
                ];
            }

            $status = $response->status();
            if ($status === 429) {
                $connectionStatus = 'Rate Limited (HTTP 429)';
                $errorReason = 'GDACS API rate limit reached (HTTP 429).';
            } else {
                $connectionStatus = "Upstream HTTP {$status}";
                $errorReason = "GDACS API returned HTTP {$status}.";
            }
            Log::warning("GDACS API returned non-200 status: {$status}");
        } catch (Throwable $e) {
            $connectionStatus = 'Timeout / Network Error';
            $errorReason = 'GDACS request failed: ' . $e->getMessage();
            Log::error('GDACS API fetch error: ' . $e->getMessage());
        }

        // Return stale cache if available
        if (is_array($cached) && is_array($cachedMeta)) {
            $staleMeta = $this->refreshMetaTimestamps($cachedMeta, $ttl, false);
            $staleMeta['status'] = 'Stale';
            $staleMeta['freshness_status'] = 'Stale';
            $staleMeta['connection_status'] = "Serving Stale Cache ({$connectionStatus})";
            $staleMeta['is_stale'] = true;
            $staleMeta['is_fresh'] = false;
            $staleMeta['error'] = $errorReason . ' Serving last verified cached data.';

            return [
                'events' => $cached,
                'meta' => $staleMeta,
            ];
        }

        // Completely unavailable fallback
        return [
            'events' => [],
            'meta' => [
                'source' => 'GDACS',
                'name' => 'Global Disaster Alert and Coordination System (UN / EC JRC)',
                'status' => 'Unavailable',
                'freshness_status' => 'Unavailable',
                'connection_status' => $connectionStatus,
                'last_successful_fetch' => null,
                'latest_event_time' => null,
                'total_features_fetched' => 0,
                'sea_events_count' => 0,
                'cache_ttl_seconds' => $ttl,
                'provider_update_frequency' => 'Every 5–15 minutes (event-driven bulletins)',
                'frontend_poll_interval_seconds' => 60,
                'is_fresh' => false,
                'is_stale' => false,
                'error' => $errorReason,
                'documentation_url' => 'https://www.gdacs.org/gdacsapi/swagger/index.html',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function refreshMetaTimestamps(array $meta, int $ttl, bool $isFresh): array
    {
        $meta['cache_ttl_seconds'] = $ttl;
        $meta['provider_update_frequency'] = 'Every 5–15 minutes (event-driven bulletins)';
        $meta['frontend_poll_interval_seconds'] = 60;
        $meta['is_fresh'] = $isFresh;
        $meta['is_stale'] = !$isFresh;
        $meta['freshness_status'] = $isFresh ? 'Fresh' : 'Stale';
        $meta['status'] = $isFresh ? 'Live' : 'Stale';

        return $meta;
    }

    /**
     * @param array<int, array<string, mixed>> $features
     * @return array<int, array<string, mixed>>
     */
    private function processFeatures(array $features): array
    {
        $events = [];

        foreach ($features as $feature) {
            if (!is_array($feature)) {
                continue;
            }

            $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $geom = is_array($feature['geometry'] ?? null) ? $feature['geometry'] : [];
            $coords = $geom['coordinates'] ?? null;

            $eventId = trim((string) ($props['eventid'] ?? ''));
            $eventType = strtoupper(trim((string) ($props['eventtype'] ?? '')));

            // Never invent IDs for malformed features
            if ($eventId === '' || $eventType === '') {
                continue;
            }

            $lat = null;
            $lng = null;
            if (is_array($coords) && count($coords) >= 2 && is_numeric($coords[0]) && is_numeric($coords[1])) {
                // GeoJSON format is [lng, lat]
                $candidateLng = (float) $coords[0];
                $candidateLat = (float) $coords[1];
                if ($candidateLat >= -90.0 && $candidateLat <= 90.0 && $candidateLng >= -180.0 && $candidateLng <= 180.0) {
                    $lng = $candidateLng;
                    $lat = $candidateLat;
                }
            }

            $iso3 = strtoupper(trim((string) ($props['iso3'] ?? '')));
            $countryName = trim((string) ($props['country'] ?? ''));
            $affectedCountries = is_array($props['affectedcountries'] ?? null) ? $props['affectedcountries'] : [];

            // Check if this event belongs to or affects Southeast Asia
            $matchedCountry = $this->matchAseanCountry($iso3, $countryName, $affectedCountries, $lat, $lng);
            if ($matchedCountry === null) {
                continue;
            }

            $resolvedIso3 = in_array($iso3, self::ASEAN_ISO3, true)
                ? $iso3
                : (self::COUNTRY_TO_ISO3[$matchedCountry] ?? ($iso3 !== '' ? $iso3 : null));

            $category = $this->mapEventTypeToCategory($eventType);
            $episodeId = trim((string) ($props['episodeid'] ?? ''));
            $id = 'gdacs_' . strtolower($eventType) . '_' . $eventId;

            // Exact official GDACS alert level: Green, Orange, Red (only when supplied)
            $rawAlertLevel = ucfirst(strtolower(trim((string) ($props['alertlevel'] ?? ''))));
            $alertLevel = in_array($rawAlertLevel, ['Green', 'Orange', 'Red'], true) ? $rawAlertLevel : null;

            // Severity details (only when supplied by GDACS)
            $severityData = is_array($props['severitydata'] ?? null) ? $props['severitydata'] : [];
            $severityVal = isset($severityData['severity']) && is_numeric($severityData['severity'])
                ? (float) $severityData['severity']
                : null;
            $rawSeverityText = $severityData['severitytext'] ?? null;
            $severityTextClean = is_string($rawSeverityText) && trim(strip_tags($rawSeverityText)) !== ''
                ? trim(strip_tags($rawSeverityText))
                : null;

            $severityDisplay = null;
            if ($alertLevel !== null && $severityTextClean !== null) {
                $severityDisplay = "{$alertLevel} Alert ({$severityTextClean})";
            } elseif ($severityTextClean !== null) {
                $severityDisplay = $severityTextClean;
            } elseif ($alertLevel !== null) {
                $severityDisplay = "{$alertLevel} Alert";
            }

            // Time parsing (never invent timestamps)
            $eventTime = $this->parseTimestamp($props['fromdate'] ?? null);
            $lastUpdatedTime = $this->parseTimestamp($props['datemodified'] ?? ($props['todate'] ?? null));

            // Source URL
            $urlProps = is_array($props['url'] ?? null) ? $props['url'] : [];
            $reportUrl = isset($urlProps['report']) && is_string($urlProps['report']) ? trim($urlProps['report']) : null;
            if (empty($reportUrl)) {
                $reportUrl = "https://www.gdacs.org/report.aspx?eventid={$eventId}"
                    . ($episodeId !== '' ? "&episodeid={$episodeId}" : '')
                    . "&eventtype={$eventType}";
            }

            $rawTitle = trim((string) ($props['name'] ?? $props['eventname'] ?? ''));
            if ($rawTitle === '') {
                continue;
            }

            $rawDescription = trim(strip_tags((string) ($props['description'] ?? $props['htmldescription'] ?? '')));
            $location = $this->extractLocation($countryName, $affectedCountries, $matchedCountry);

            $depthKm = ($eventType === 'EQ' && $severityTextClean !== null && str_contains(strtolower($severityTextClean), 'depth'))
                ? $this->extractDepthFromText($severityTextClean)
                : null;

            $magnitude = ($eventType === 'EQ' && $severityVal !== null && $severityVal > 0)
                ? round($severityVal, 1)
                : null;

            $events[] = [
                'id' => $id,
                'source' => 'GDACS',
                'source_id' => $eventId,
                'disaster_type' => $category,
                'category' => $category,
                'raw_type' => $eventType,
                'title' => $rawTitle,
                'description' => $rawDescription !== '' ? $rawDescription : null,
                'country' => $matchedCountry,
                'location' => $location,
                'iso3' => $resolvedIso3,
                'latitude' => $lat,
                'longitude' => $lng,
                'coordinates' => ($lat !== null && $lng !== null) ? [$lat, $lng] : null,
                'magnitude' => $magnitude,
                'depth_km' => $depthKm,
                'severity' => $severityDisplay,
                'severity_level' => $alertLevel,
                'severity_score' => $severityVal,
                'severity_text' => $severityTextClean ?? ($alertLevel ? "{$alertLevel} Alert" : null),
                'event_date' => $eventTime,
                'event_time' => $eventTime,
                'last_updated_time' => $lastUpdatedTime,
                'source_url' => $reportUrl,
            ];
        }

        return $events;
    }

    /**
     * @param array<int, array<string, mixed>> $affectedCountries
     */
    private function extractLocation(string $countryName, array $affectedCountries, string $matchedCountry): ?string
    {
        $names = [];
        foreach ($affectedCountries as $ac) {
            if (is_array($ac) && !empty($ac['countryname'])) {
                $names[] = trim((string) $ac['countryname']);
            }
        }
        $names = array_values(array_unique(array_filter($names)));

        if (!empty($names)) {
            return implode(', ', $names);
        }

        if ($countryName !== '') {
            return $countryName;
        }

        return $matchedCountry !== '' ? $matchedCountry : null;
    }

    private function parseTimestamp(mixed $raw): ?string
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $affectedCountries
     */
    private function matchAseanCountry(string $iso3, string $countryName, array $affectedCountries, ?float $lat, ?float $lng): ?string
    {
        // 1. Direct ISO3 match
        if (in_array($iso3, self::ASEAN_ISO3, true)) {
            $mapped = self::ASEAN_NAMES[strtolower($countryName)] ?? null;
            if ($mapped !== null) {
                return $mapped;
            }
            $byIso = array_search($iso3, self::COUNTRY_TO_ISO3, true);
            return is_string($byIso) ? $byIso : $countryName;
        }

        // 2. Direct name match
        $lowerCountry = strtolower($countryName);
        if (isset(self::ASEAN_NAMES[$lowerCountry])) {
            return self::ASEAN_NAMES[$lowerCountry];
        }

        foreach (self::ASEAN_NAMES as $key => $canonical) {
            if ($lowerCountry !== '' && str_contains($lowerCountry, $key)) {
                return $canonical;
            }
        }

        // 3. Affected countries list
        foreach ($affectedCountries as $ac) {
            if (!is_array($ac)) {
                continue;
            }
            $acIso3 = strtoupper(trim((string) ($ac['iso3'] ?? '')));
            $acName = strtolower(trim((string) ($ac['countryname'] ?? '')));
            if (in_array($acIso3, self::ASEAN_ISO3, true)) {
                $byIso = array_search($acIso3, self::COUNTRY_TO_ISO3, true);
                return self::ASEAN_NAMES[$acName] ?? (is_string($byIso) ? $byIso : ($ac['countryname'] ?? 'Southeast Asia'));
            }
            if (isset(self::ASEAN_NAMES[$acName])) {
                return self::ASEAN_NAMES[$acName];
            }
        }

        // 4. Coordinate bounding box fallback only for unnamed/marine events without a non-ASEAN country assignment
        if ($iso3 === '' && $countryName === '' && $lat !== null && $lng !== null) {
            if ($lat >= -11.0 && $lat <= 21.5 && $lng >= 94.0 && $lng <= 135.0) {
                return 'Southeast Asia';
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
