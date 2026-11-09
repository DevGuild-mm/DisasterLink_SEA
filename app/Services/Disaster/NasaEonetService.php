<?php

namespace App\Services\Disaster;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class NasaEonetService
{
    private const CACHE_KEY = 'nasa_eonet_events_sea';

    private const CACHE_META_KEY = 'nasa_eonet_meta_sea';

    private const CACHE_FRESH_KEY = 'nasa_eonet_fresh_lock_sea';

    private const DEFAULT_TTL_SECONDS = 900; // 15 minutes

    private const STALE_BACKUP_TTL_SECONDS = 86400; // 24 hours backup for stale fallback

    private const MIN_FORCE_REFRESH_INTERVAL = 15; // seconds

    // Southeast Asia Bounding Box
    private const MIN_LAT = -11.5;

    private const MAX_LAT = 28.5;

    private const MIN_LNG = 92.0;

    private const MAX_LNG = 142.0;

    private const CATEGORY_MAP = [
        'wildfires' => 'Wildfire',
        'severestorms' => 'Severe Storm',
        'volcanoes' => 'Volcano',
        'floods' => 'Flood',
        'earthquakes' => 'Earthquake',
        'landslides' => 'Landslide',
        'drought' => 'Drought',
        'tempextremes' => 'Temperature Extreme',
    ];

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
     * Retrieve natural events from NASA EONET v3.
     *
     * @return array{events: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function getEvents(bool $forceRefresh = false): array
    {
        $ttl = (int) config('services.disaster_apis.eonet.cache_ttl', self::DEFAULT_TTL_SECONDS);
        $staleTtl = (int) config('services.disaster_apis.stale_ttl', self::STALE_BACKUP_TTL_SECONDS);
        $timeout = (int) config('services.disaster_apis.timeout', 8);
        $url = (string) config(
            'services.disaster_apis.eonet.url',
            'https://eonet.gsfc.nasa.gov/api/v3/events',
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

        $errorReason = 'Unable to contact NASA EONET server.';
        $connectionStatus = 'Connection Failed';

        try {
            // EONET API v3 query with bounding box (minLng, minLat, maxLng, maxLat)
            $response = Http::timeout($timeout)
                ->connectTimeout(5)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'DisasterLinkSEA/1.0',
                ])
                ->get($url, [
                    'status' => 'all',
                    'days' => 30,
                    'limit' => 50,
                    'bbox' => '92,-12,142,29',
                ]);

            if ($response->successful()) {
                $data = $response->json();
                if (! is_array($data) || ! isset($data['events']) || ! is_array($data['events'])) {
                    $connectionStatus = 'Invalid Response Format';
                    throw new \RuntimeException('Invalid JSON structure from NASA EONET v3 API.');
                }

                $rawEvents = $data['events'];
                $normalizedEvents = $this->processEvents($rawEvents);

                $latestEventTime = null;
                foreach ($normalizedEvents as $ev) {
                    if (! empty($ev['event_time']) && ($latestEventTime === null || $ev['event_time'] > $latestEventTime)) {
                        $latestEventTime = $ev['event_time'];
                    }
                }

                $nowIso = Carbon::now()->toIso8601String();
                $meta = [
                    'source' => 'NASA EONET',
                    'name' => 'NASA Earth Observatory Natural Event Tracker (EONET v3)',
                    'status' => 'Live',
                    'freshness_status' => 'Fresh',
                    'connection_status' => 'Connected (HTTP 200)',
                    'last_successful_fetch' => $nowIso,
                    'latest_event_time' => $latestEventTime,
                    'total_features_fetched' => count($rawEvents),
                    'sea_events_count' => count($normalizedEvents),
                    'cache_ttl_seconds' => $ttl,
                    'provider_update_frequency' => 'Sub-daily satellite & partner agency updates',
                    'frontend_poll_interval_seconds' => 60,
                    'is_fresh' => true,
                    'is_stale' => false,
                    'error' => null,
                    'documentation_url' => 'https://eonet.gsfc.nasa.gov/api/v3/events',
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
            $connectionStatus = $status === 429 ? 'Rate Limited (HTTP 429)' : "Upstream HTTP {$status}";
            $errorReason = "NASA EONET API returned HTTP {$status}.";
            Log::warning("NASA EONET API returned non-200: {$status}");
        } catch (Throwable $e) {
            if ($connectionStatus === 'Connection Failed') {
                $connectionStatus = 'Timeout / Network Error';
            }
            $errorReason = 'NASA EONET fetch failed: '.$e->getMessage();
            Log::error('NASA EONET API fetch error: '.$e->getMessage());
        }

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
                'source' => 'NASA EONET',
                'name' => 'NASA Earth Observatory Natural Event Tracker (EONET v3)',
                'status' => 'Unavailable',
                'freshness_status' => 'Unavailable',
                'connection_status' => $connectionStatus,
                'last_successful_fetch' => null,
                'latest_event_time' => null,
                'total_features_fetched' => 0,
                'sea_events_count' => 0,
                'cache_ttl_seconds' => $ttl,
                'provider_update_frequency' => 'Sub-daily satellite & partner agency updates',
                'frontend_poll_interval_seconds' => 60,
                'is_fresh' => false,
                'is_stale' => false,
                'error' => $errorReason,
                'documentation_url' => 'https://eonet.gsfc.nasa.gov/api/v3/events',
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
        $meta['provider_update_frequency'] = 'Sub-daily satellite & partner agency updates';
        $meta['frontend_poll_interval_seconds'] = 60;
        $meta['is_fresh'] = $isFresh;
        $meta['is_stale'] = ! $isFresh;
        $meta['freshness_status'] = $isFresh ? 'Fresh' : 'Stale';
        $meta['status'] = $isFresh ? 'Live' : 'Stale';

        return $meta;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rawEvents
     * @return array<int, array<string, mixed>>
     */
    private function processEvents(array $rawEvents): array
    {
        $events = [];

        foreach ($rawEvents as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = trim((string) ($item['id'] ?? ''));
            $title = trim((string) ($item['title'] ?? ''));
            if ($id === '' || $title === '') {
                continue;
            }

            // Categories
            $categories = is_array($item['categories'] ?? null) ? $item['categories'] : [];
            $catId = ! empty($categories) && is_array($categories[0]) ? (string) ($categories[0]['id'] ?? '') : '';
            $catTitle = ! empty($categories) && is_array($categories[0]) ? (string) ($categories[0]['title'] ?? 'Natural Event') : 'Natural Event';
            $normalizedCategory = self::CATEGORY_MAP[strtolower($catId)] ?? $catTitle;

            // Geometry & date: pick the latest geometry observation supplied by NASA EONET
            $geometryList = is_array($item['geometry'] ?? null) ? $item['geometry'] : [];
            $eventDate = null;
            $lat = null;
            $lng = null;
            $magnitudeVal = null;
            $magnitudeUnit = null;
            $observations = [];

            foreach ($geometryList as $g) {
                if (is_array($g) && ! empty($g['date'])) {
                    $observations[] = [
                        'date' => $g['date'],
                        'magnitude' => isset($g['magnitudeValue']) && is_numeric($g['magnitudeValue']) ? (float) $g['magnitudeValue'] : null,
                        'unit' => ! empty($g['magnitudeUnit']) && is_string($g['magnitudeUnit']) ? trim($g['magnitudeUnit']) : null,
                    ];
                }
            }

            if (! empty($geometryList)) {
                $latestGeom = end($geometryList);
                if (is_array($latestGeom)) {
                    $eventDate = $latestGeom['date'] ?? null;
                    $rawCoords = $latestGeom['coordinates'] ?? null;
                    $geomType = (string) ($latestGeom['type'] ?? 'Point');

                    if (isset($latestGeom['magnitudeValue']) && is_numeric($latestGeom['magnitudeValue'])) {
                        $magnitudeVal = (float) $latestGeom['magnitudeValue'];
                    }
                    if (! empty($latestGeom['magnitudeUnit']) && is_string($latestGeom['magnitudeUnit'])) {
                        $magnitudeUnit = trim($latestGeom['magnitudeUnit']);
                    }

                    if ($geomType === 'Point' && is_array($rawCoords) && count($rawCoords) >= 2 && is_numeric($rawCoords[0]) && is_numeric($rawCoords[1])) {
                        $lng = (float) $rawCoords[0];
                        $lat = (float) $rawCoords[1];
                    } elseif (is_array($rawCoords)) {
                        $centroid = $this->calculateCentroid($rawCoords);
                        if ($centroid !== null) {
                            $lng = $centroid[0];
                            $lat = $centroid[1];
                        }
                    }
                }
            }

            // Ensure event falls in Southeast Asia or references an ASEAN country
            $country = $this->detectCountry($title, $lat, $lng);
            if ($lat !== null && $lng !== null) {
                if ($lat < self::MIN_LAT || $lat > self::MAX_LAT || $lng < self::MIN_LNG || $lng > self::MAX_LNG) {
                    if ($country === null) {
                        continue;
                    }
                }
            }

            if ($country === null) {
                continue;
            }

            $iso3 = self::COUNTRY_TO_ISO3[$country] ?? null;

            $eventTime = null;
            if (is_string($eventDate) && trim($eventDate) !== '') {
                try {
                    $eventTime = Carbon::parse($eventDate)->toIso8601String();
                } catch (Throwable) {
                    $eventTime = null;
                }
            }

            $closedTime = null;
            if (! empty($item['closed']) && is_string($item['closed'])) {
                try {
                    $closedTime = Carbon::parse($item['closed'])->toIso8601String();
                } catch (Throwable) {
                    $closedTime = null;
                }
            }

            // Only include severity when genuinely supplied by NASA EONET geometry
            $severityText = null;
            if ($magnitudeVal !== null) {
                $severityText = $magnitudeUnit !== null && $magnitudeUnit !== ''
                    ? "{$magnitudeVal} {$magnitudeUnit}"
                    : (string) $magnitudeVal;
            }

            // Sources
            $sources = is_array($item['sources'] ?? null) ? $item['sources'] : [];
            $sourceUrl = ! empty($sources) && is_array($sources[0]) ? ($sources[0]['url'] ?? null) : null;
            if (empty($sourceUrl) || ! is_string($sourceUrl)) {
                $sourceUrl = (string) ($item['link'] ?? "https://eonet.gsfc.nasa.gov/api/v3/events/{$id}");
            }

            $rawDescription = isset($item['description']) && is_string($item['description']) && trim($item['description']) !== ''
                ? trim($item['description'])
                : null;

            $events[] = [
                'id' => 'eonet_'.$id,
                'source' => 'NASA EONET',
                'source_id' => $id,
                'disaster_type' => $normalizedCategory,
                'category' => $normalizedCategory,
                'raw_type' => $catId !== '' ? $catId : 'natural_event',
                'title' => $title,
                'description' => $rawDescription,
                'country' => $country,
                'location' => $title,
                'iso3' => $iso3,
                'latitude' => $lat,
                'longitude' => $lng,
                'coordinates' => ($lat !== null && $lng !== null) ? [$lat, $lng] : null,
                'magnitude' => null,
                'depth_km' => null,
                'severity' => $severityText,
                'severity_level' => null,
                'severity_score' => $magnitudeVal,
                'severity_text' => $severityText,
                'event_date' => $eventTime,
                'event_time' => $eventTime,
                'last_updated_time' => $closedTime ?? $eventTime,
                'observations' => $observations,
                'source_url' => $sourceUrl,
            ];
        }

        return $events;
    }

    private function detectCountry(string $title, ?float $lat, ?float $lng): ?string
    {
        $lower = strtolower($title);
        foreach (self::ASEAN_KEYWORDS as $keyword => $country) {
            if (str_contains($lower, $keyword)) {
                return $country;
            }
        }

        $nonAseanKeywords = [
            'japan', 'taiwan', 'china', 'india', 'bangladesh', 'bhutan',
            'nepal', 'papua new guinea', 'australia', 'palau', 'guam', 'mariana',
        ];
        foreach ($nonAseanKeywords as $nonAsean) {
            if (str_contains($lower, $nonAsean)) {
                return null;
            }
        }

        if ($lat !== null && $lng !== null) {
            if ($lat >= -11.0 && $lat <= 21.5 && $lng >= 94.0 && $lng <= 135.0) {
                return 'Southeast Asia';
            }
        }

        return null;
    }

    /**
     * Compute approximate centroid for polygon / multipolygon array.
     *
     * @param  array<mixed>  $coords
     * @return array{0: float, 1: float}|null
     */
    private function calculateCentroid(array $coords): ?array
    {
        $flattened = [];
        array_walk_recursive($coords, function ($val) use (&$flattened) {
            if (is_numeric($val)) {
                $flattened[] = (float) $val;
            }
        });

        $count = count($flattened);
        if ($count < 2 || $count % 2 !== 0) {
            return null;
        }

        $sumLng = 0.0;
        $sumLat = 0.0;
        $pairs = $count / 2;

        for ($i = 0; $i < $count; $i += 2) {
            $sumLng += $flattened[$i];
            $sumLat += $flattened[$i + 1];
        }

        return [$sumLng / $pairs, $sumLat / $pairs];
    }
}
