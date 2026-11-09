<?php

namespace App\Services\Disaster;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class UsgsService
{
    private const CACHE_KEY = 'usgs_earthquakes_sea';

    private const CACHE_META_KEY = 'usgs_meta_sea';

    private const CACHE_FRESH_KEY = 'usgs_fresh_lock_sea';

    private const DEFAULT_TTL_SECONDS = 180; // 3 minutes

    private const STALE_BACKUP_TTL_SECONDS = 86400; // 24 hours backup for stale fallback

    private const MIN_FORCE_REFRESH_INTERVAL = 15; // seconds

    // Southeast Asia Bounding Box
    private const MIN_LAT = -11.5;

    private const MAX_LAT = 28.5;

    private const MIN_LNG = 92.0;

    private const MAX_LNG = 142.0;

    private const ASEAN_KEYWORDS = [
        'indonesia' => 'Indonesia',
        'philippines' => 'Philippines',
        'myanmar' => 'Myanmar',
        'burma' => 'Myanmar',
        'malaysia' => 'Malaysia',
        'thailand' => 'Thailand',
        'vietnam' => 'Vietnam',
        'cambodia' => 'Cambodia',
        'laos' => 'Laos',
        'brunei' => 'Brunei',
        'timor-leste' => 'Timor-Leste',
        'east timor' => 'Timor-Leste',
        'singapore' => 'Singapore',
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

    /**
     * Retrieve USGS earthquakes filtered to Southeast Asia.
     *
     * @return array{events: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function getEvents(bool $forceRefresh = false): array
    {
        $ttl = (int) config('services.disaster_apis.usgs.cache_ttl', self::DEFAULT_TTL_SECONDS);
        $staleTtl = (int) config('services.disaster_apis.stale_ttl', self::STALE_BACKUP_TTL_SECONDS);
        $timeout = (int) config('services.disaster_apis.timeout', 8);
        $hourUrl = (string) config(
            'services.disaster_apis.usgs.hour_url',
            'https://earthquake.usgs.gov/earthquakes/feed/v1.0/summary/all_hour.geojson',
        );
        $weekUrl = (string) config(
            'services.disaster_apis.usgs.week_url',
            'https://earthquake.usgs.gov/earthquakes/feed/v1.0/summary/all_week.geojson',
        );

        $cached = Cache::get(self::CACHE_KEY);
        $cachedMeta = Cache::get(self::CACHE_META_KEY);
        $isFreshLock = Cache::has(self::CACHE_FRESH_KEY);

        if ($cached !== null && $cachedMeta !== null) {
            if (! $forceRefresh && $isFreshLock) {
                return [
                    'events' => $cached,
                    'meta' => $this->refreshMetaTimestamps($cachedMeta, $ttl, true),
                ];
            }

            if ($forceRefresh && ! empty($cachedMeta['last_successful_fetch'])) {
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

        $errorReason = 'Unable to contact USGS Earthquake Hazards server.';
        $connectionStatus = 'Connection Failed';

        try {
            // 1. Fetch required primary feed: all_hour.geojson
            $hourResponse = Http::timeout($timeout)
                ->connectTimeout(5)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'DisasterLinkSEA/1.0',
                ])
                ->get($hourUrl);

            if (! $hourResponse->successful()) {
                $status = $hourResponse->status();
                $connectionStatus = $status === 429 ? 'Rate Limited (HTTP 429)' : "Upstream HTTP {$status}";
                throw new \RuntimeException("USGS all_hour.geojson returned HTTP {$status}");
            }

            $hourJson = $hourResponse->json();
            if (! is_array($hourJson) || ! isset($hourJson['features']) || ! is_array($hourJson['features'])) {
                $connectionStatus = 'Invalid Response Format';
                throw new \RuntimeException('Invalid GeoJSON structure from USGS all_hour.geojson');
            }

            $featuresHour = $hourJson['features'];
            $eventsHour = $this->filterAndNormalizeFeatures($featuresHour);

            // 2. Also fetch recent regional context (all_week.geojson) if reachable
            $featuresWeek = [];
            $eventsWeek = [];
            try {
                $weekResponse = Http::timeout($timeout)
                    ->connectTimeout(5)
                    ->withHeaders([
                        'Accept' => 'application/json',
                        'User-Agent' => 'DisasterLinkSEA/1.0',
                    ])
                    ->get($weekUrl);

                if ($weekResponse->successful()) {
                    $weekJson = $weekResponse->json();
                    if (is_array($weekJson) && isset($weekJson['features']) && is_array($weekJson['features'])) {
                        $featuresWeek = $weekJson['features'];
                        $eventsWeek = $this->filterAndNormalizeFeatures($featuresWeek);
                    }
                }
            } catch (Throwable $weekErr) {
                Log::warning('USGS supplementary week feed failed: '.$weekErr->getMessage());
            }

            // Merge with all_hour.geojson taking precedence, deduplicating by source_id
            $allEventsMap = [];
            foreach ($eventsHour as $ev) {
                $allEventsMap[$ev['source_id']] = $ev;
            }
            foreach ($eventsWeek as $ev) {
                if (! isset($allEventsMap[$ev['source_id']])) {
                    $allEventsMap[$ev['source_id']] = $ev;
                }
            }

            // Sort by event_time DESC (newest first)
            $mergedEvents = array_values($allEventsMap);
            usort($mergedEvents, function ($a, $b) {
                return strcmp($b['event_time'] ?? '', $a['event_time'] ?? '');
            });

            // Keep top 30 most recent earthquakes in the region
            $finalEvents = array_slice($mergedEvents, 0, 30);

            $latestEventTime = ! empty($finalEvents) ? ($finalEvents[0]['event_time'] ?? null) : null;
            $nowIso = Carbon::now()->toIso8601String();

            $meta = [
                'source' => 'USGS',
                'name' => 'United States Geological Survey (Earthquake Hazards Program)',
                'status' => 'Live',
                'freshness_status' => 'Fresh',
                'connection_status' => 'Connected (HTTP 200)',
                'last_successful_fetch' => $nowIso,
                'latest_event_time' => $latestEventTime,
                'total_features_fetched' => count($featuresHour) + count($featuresWeek),
                'sea_events_count' => count($finalEvents),
                'cache_ttl_seconds' => $ttl,
                'provider_update_frequency' => 'Every 1 minute (all_hour.geojson feed)',
                'frontend_poll_interval_seconds' => 60,
                'is_fresh' => true,
                'is_stale' => false,
                'error' => null,
                'documentation_url' => 'https://earthquake.usgs.gov/earthquakes/feed/v1.0/summary/all_hour.geojson',
            ];

            Cache::put(self::CACHE_FRESH_KEY, true, $ttl);
            Cache::put(self::CACHE_KEY, $finalEvents, $staleTtl);
            Cache::put(self::CACHE_META_KEY, $meta, $staleTtl);

            return [
                'events' => $finalEvents,
                'meta' => $meta,
            ];
        } catch (Throwable $e) {
            if ($connectionStatus === 'Connection Failed') {
                $connectionStatus = 'Timeout / Network Error';
            }
            $errorReason = 'USGS feed error: '.$e->getMessage();
            Log::error('USGS API fetch error: '.$e->getMessage());
        }

        // Return stale cache if available
        if (is_array($cached) && is_array($cachedMeta)) {
            $staleMeta = $this->refreshMetaTimestamps($cachedMeta, $ttl, false);
            $staleMeta['status'] = 'Stale';
            $staleMeta['freshness_status'] = 'Stale';
            $staleMeta['connection_status'] = "Serving Stale Cache ({$connectionStatus})";
            $staleMeta['is_stale'] = true;
            $staleMeta['is_fresh'] = false;
            $staleMeta['error'] = $errorReason.' Serving last verified cached data.';

            return [
                'events' => $cached,
                'meta' => $staleMeta,
            ];
        }

        return [
            'events' => [],
            'meta' => [
                'source' => 'USGS',
                'name' => 'United States Geological Survey (Earthquake Hazards Program)',
                'status' => 'Unavailable',
                'freshness_status' => 'Unavailable',
                'connection_status' => $connectionStatus,
                'last_successful_fetch' => null,
                'latest_event_time' => null,
                'total_features_fetched' => 0,
                'sea_events_count' => 0,
                'cache_ttl_seconds' => $ttl,
                'provider_update_frequency' => 'Every 1 minute (all_hour.geojson feed)',
                'frontend_poll_interval_seconds' => 60,
                'is_fresh' => false,
                'is_stale' => false,
                'error' => $errorReason,
                'documentation_url' => 'https://earthquake.usgs.gov/earthquakes/feed/v1.0/summary/all_hour.geojson',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function refreshMetaTimestamps(array $meta, int $ttl, bool $isFresh): array
    {
        $meta['cache_ttl_seconds'] = $ttl;
        $meta['provider_update_frequency'] = 'Every 1 minute (all_hour.geojson feed)';
        $meta['frontend_poll_interval_seconds'] = 60;
        $meta['is_fresh'] = $isFresh;
        $meta['is_stale'] = ! $isFresh;
        $meta['freshness_status'] = $isFresh ? 'Fresh' : 'Stale';
        $meta['status'] = $isFresh ? 'Live' : 'Stale';

        return $meta;
    }

    /**
     * @param  array<int, array<string, mixed>>  $features
     * @return array<int, array<string, mixed>>
     */
    private function filterAndNormalizeFeatures(array $features): array
    {
        $events = [];

        foreach ($features as $f) {
            if (! is_array($f)) {
                continue;
            }

            $id = trim((string) ($f['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $geom = is_array($f['geometry'] ?? null) ? $f['geometry'] : [];
            $coords = $geom['coordinates'] ?? [];
            if (! is_array($coords) || count($coords) < 2 || ! is_numeric($coords[0]) || ! is_numeric($coords[1])) {
                continue;
            }

            $lng = (float) $coords[0];
            $lat = (float) $coords[1];
            $depthKm = isset($coords[2]) && is_numeric($coords[2]) ? round((float) $coords[2], 1) : null;

            // Geographic bounding box filter for Southeast Asia
            if ($lat < self::MIN_LAT || $lat > self::MAX_LAT || $lng < self::MIN_LNG || $lng > self::MAX_LNG) {
                continue;
            }

            $props = is_array($f['properties'] ?? null) ? $f['properties'] : [];
            $place = trim((string) ($props['place'] ?? ''));
            $country = $this->detectCountryFromPlace($place, $lat, $lng);
            if ($country === null) {
                continue;
            }
            $iso3 = self::COUNTRY_TO_ISO3[$country] ?? null;

            $mag = isset($props['mag']) && is_numeric($props['mag']) ? round((float) $props['mag'], 1) : null;

            // Respect USGS PAGER alert if supplied, or seismic magnitude classification when magnitude is supplied
            $pagerAlert = isset($props['alert']) && is_string($props['alert'])
                ? ucfirst(strtolower(trim($props['alert'])))
                : null;

            $severityLevel = null;
            if (in_array($pagerAlert, ['Red', 'Orange', 'Green'], true)) {
                $severityLevel = $pagerAlert;
            } elseif ($pagerAlert === 'Yellow') {
                $severityLevel = 'Orange';
            } elseif ($mag !== null) {
                if ($mag >= 6.5) {
                    $severityLevel = 'Red';
                } elseif ($mag >= 5.0) {
                    $severityLevel = 'Orange';
                } else {
                    $severityLevel = 'Green';
                }
            }

            $severityText = $mag !== null ? 'Magnitude '.number_format($mag, 1).'M' : null;
            $severityDisplay = $mag !== null
                ? 'M '.number_format($mag, 1).($severityLevel ? " ({$severityLevel})" : '')
                : $severityLevel;

            // Timestamp in milliseconds -> ISO8601 (never invent timestamps)
            $timeMs = $props['time'] ?? null;
            $eventTime = is_numeric($timeMs)
                ? Carbon::createFromTimestampMs((int) $timeMs)->toIso8601String()
                : null;

            $updatedMs = $props['updated'] ?? null;
            $lastUpdatedTime = is_numeric($updatedMs)
                ? Carbon::createFromTimestampMs((int) $updatedMs)->toIso8601String()
                : null;

            $title = trim((string) ($props['title'] ?? ''));
            if ($title === '') {
                $title = $mag !== null
                    ? "M {$mag} Earthquake".($place !== '' ? " - {$place}" : '')
                    : 'Seismic Event';
            }

            $url = isset($props['url']) && is_string($props['url']) && trim($props['url']) !== ''
                ? trim($props['url'])
                : "https://earthquake.usgs.gov/earthquakes/eventpage/{$id}";

            $descParts = [];
            if ($place !== '') {
                $descParts[] = "Location: {$place}";
            }
            if ($mag !== null) {
                $descParts[] = "Magnitude: M {$mag}";
            }
            if ($depthKm !== null) {
                $descParts[] = "Depth: {$depthKm} km";
            }
            if (! empty($props['tsunami'])) {
                $descParts[] = 'Tsunami Advisory Flagged';
            }

            $events[] = [
                'id' => 'usgs_'.$id,
                'source' => 'USGS',
                'source_id' => $id,
                'disaster_type' => 'Earthquake',
                'category' => 'Earthquake',
                'raw_type' => (string) ($props['type'] ?? 'earthquake'),
                'title' => $title,
                'description' => ! empty($descParts) ? implode(' • ', $descParts) : null,
                'country' => $country,
                'location' => $place !== '' ? $place : null,
                'iso3' => $iso3,
                'latitude' => $lat,
                'longitude' => $lng,
                'coordinates' => [$lat, $lng],
                'magnitude' => $mag,
                'depth_km' => $depthKm,
                'severity' => $severityDisplay,
                'severity_level' => $severityLevel,
                'severity_score' => $mag,
                'severity_text' => $severityText,
                'event_date' => $eventTime,
                'event_time' => $eventTime,
                'last_updated_time' => $lastUpdatedTime,
                'source_url' => $url,
            ];
        }

        return $events;
    }

    private function detectCountryFromPlace(string $place, float $lat, float $lng): ?string
    {
        $lower = strtolower($place);
        foreach (self::ASEAN_KEYWORDS as $keyword => $country) {
            if (str_contains($lower, $keyword)) {
                return $country;
            }
        }

        // Check regional seas within Southeast Asia
        if (
            str_contains($lower, 'celebes') ||
            str_contains($lower, 'java') ||
            str_contains($lower, 'molucca') ||
            str_contains($lower, 'flores') ||
            str_contains($lower, 'banda') ||
            str_contains($lower, 'halmahera') ||
            str_contains($lower, 'seram') ||
            str_contains($lower, 'sunda') ||
            str_contains($lower, 'sumatra') ||
            str_contains($lower, 'sulawesi') ||
            str_contains($lower, 'timor sea') ||
            str_contains($lower, 'arafura')
        ) {
            return 'Indonesia';
        }
        if (
            str_contains($lower, 'luzon') ||
            str_contains($lower, 'mindanao') ||
            str_contains($lower, 'visayas') ||
            str_contains($lower, 'sulu') ||
            str_contains($lower, 'philippine')
        ) {
            return 'Philippines';
        }
        if (str_contains($lower, 'andaman') || str_contains($lower, 'martaban')) {
            return 'Myanmar';
        }
        if (str_contains($lower, 'gulf of thailand')) {
            return 'Thailand';
        }
        if (str_contains($lower, 'south china sea')) {
            return 'Southeast Asia';
        }

        // Exclude events explicitly located in non-ASEAN neighboring territories within bounding box corners
        $nonAseanKeywords = [
            'japan', 'bonin', 'ryukyu', 'taiwan', 'china', 'india', 'nicobar',
            'bangladesh', 'bhutan', 'nepal', 'papua new guinea', 'australia',
            'palau', 'guam', 'mariana', 'micronesia',
        ];
        foreach ($nonAseanKeywords as $nonAsean) {
            if (str_contains($lower, $nonAsean)) {
                return null;
            }
        }

        // Only keep generic coordinates if they fall within core ASEAN maritime/land bounds
        if ($lat >= -11.0 && $lat <= 21.5 && $lng >= 94.0 && $lng <= 135.0) {
            return 'Southeast Asia';
        }

        return null;
    }
}
