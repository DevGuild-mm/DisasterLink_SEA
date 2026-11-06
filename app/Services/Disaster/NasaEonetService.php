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
    private const CACHE_TTL_SECONDS = 900; // 15 minutes

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
        'tempExtremes' => 'Temperature Extreme',
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

    /**
     * Retrieve natural events from NASA EONET.
     *
     * @return array{events: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function getEvents(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        $cachedMeta = Cache::get(self::CACHE_META_KEY);

        if ($cached !== null && $cachedMeta !== null && ($cachedMeta['is_fresh'] ?? false)) {
            return [
                'events' => $cached,
                'meta' => $cachedMeta,
            ];
        }

        try {
            // EONET API v3 query with bounding box (minLng, minLat, maxLng, maxLat)
            $url = 'https://eonet.gsfc.nasa.gov/api/v3/events';
            $response = Http::withoutVerifying()->timeout(6)->withHeaders([
                'Accept' => 'application/json',
                'User-Agent' => 'DisasterLinkSEA/1.0',
            ])->get($url, [
                'status' => 'all',
                'days' => 30,
                'limit' => 30,
                'bbox' => '92,-12,142,29',
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $rawEvents = $data['events'] ?? [];
                $normalizedEvents = $this->processEvents($rawEvents);

                $latestEventTime = null;
                foreach ($normalizedEvents as $ev) {
                    if ($latestEventTime === null || $ev['event_time'] > $latestEventTime) {
                        $latestEventTime = $ev['event_time'];
                    }
                }

                $meta = [
                    'source' => 'NASA EONET',
                    'name' => 'NASA Earth Observatory Natural Event Tracker',
                    'status' => 'Live',
                    'last_successful_fetch' => Carbon::now()->toIso8601String(),
                    'latest_event_time' => $latestEventTime,
                    'total_features_fetched' => count($rawEvents),
                    'sea_events_count' => count($normalizedEvents),
                    'is_fresh' => true,
                    'is_stale' => false,
                    'documentation_url' => 'https://eonet.gsfc.nasa.gov/docs/v3',
                ];

                Cache::put(self::CACHE_KEY, $normalizedEvents, self::CACHE_TTL_SECONDS);
                Cache::put(self::CACHE_META_KEY, $meta, self::CACHE_TTL_SECONDS);

                return [
                    'events' => $normalizedEvents,
                    'meta' => $meta,
                ];
            }

            Log::warning('NASA EONET API returned non-200: ' . $response->status());
        } catch (Throwable $e) {
            Log::error('NASA EONET API fetch error: ' . $e->getMessage());
        }

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

        return [
            'events' => [],
            'meta' => [
                'source' => 'NASA EONET',
                'name' => 'NASA Earth Observatory Natural Event Tracker',
                'status' => 'Unavailable',
                'last_successful_fetch' => null,
                'latest_event_time' => null,
                'total_features_fetched' => 0,
                'sea_events_count' => 0,
                'is_fresh' => false,
                'is_stale' => false,
                'error' => 'Unable to contact NASA EONET server.',
                'documentation_url' => 'https://eonet.gsfc.nasa.gov/docs/v3',
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rawEvents
     * @return array<int, array<string, mixed>>
     */
    private function processEvents(array $rawEvents): array
    {
        $events = [];

        foreach ($rawEvents as $item) {
            $id = (string) ($item['id'] ?? uniqid());
            $title = trim((string) ($item['title'] ?? 'Natural Event'));

            // Categories
            $categories = $item['categories'] ?? [];
            $catId = !empty($categories) ? ($categories[0]['id'] ?? '') : '';
            $catTitle = !empty($categories) ? ($categories[0]['title'] ?? 'Other') : 'Other';
            $normalizedCategory = self::CATEGORY_MAP[strtolower($catId)] ?? $catTitle;

            // Geometry & date: can have multiple geometry entries, pick the latest
            $geometryList = $item['geometry'] ?? [];
            $coords = null;
            $eventDate = null;
            $lat = null;
            $lng = null;

            if (!empty($geometryList)) {
                $latestGeom = end($geometryList);
                $eventDate = $latestGeom['date'] ?? null;
                $rawCoords = $latestGeom['coordinates'] ?? null;
                $geomType = $latestGeom['type'] ?? 'Point';

                if ($geomType === 'Point' && is_array($rawCoords) && count($rawCoords) >= 2) {
                    $lng = (float) $rawCoords[0];
                    $lat = (float) $rawCoords[1];
                } elseif (is_array($rawCoords)) {
                    // Polygon or MultiPoint: compute centroid
                    $centroid = $this->calculateCentroid($rawCoords);
                    if ($centroid !== null) {
                        $lng = $centroid[0];
                        $lat = $centroid[1];
                    }
                }
            }

            // Ensure event falls in Southeast Asia or references Southeast Asia
            $country = $this->detectCountry($title, $lat, $lng);
            if ($lat !== null && $lng !== null) {
                if ($lat < self::MIN_LAT || $lat > self::MAX_LAT || $lng < self::MIN_LNG || $lng > self::MAX_LNG) {
                    // Coordinates outside SE Asia
                    if ($country === null) {
                        continue;
                    }
                }
            }

            if ($country === null) {
                $country = 'Southeast Asia';
            }

            $eventTime = null;
            if (!empty($eventDate)) {
                try {
                    $eventTime = Carbon::parse($eventDate)->toIso8601String();
                } catch (Throwable) {
                    $eventTime = $eventDate;
                }
            }

            // Sources
            $sources = $item['sources'] ?? [];
            $sourceUrl = !empty($sources) ? ($sources[0]['url'] ?? null) : null;
            if (empty($sourceUrl)) {
                $sourceUrl = "https://eonet.gsfc.nasa.gov/api/v3/events/{$id}";
            }

            $events[] = [
                'id' => 'eonet_' . $id,
                'source' => 'NASA EONET',
                'source_id' => $id,
                'category' => $normalizedCategory,
                'raw_type' => $catId,
                'title' => $title,
                'description' => "Category: {$catTitle}" . (!empty($item['description']) ? " | {$item['description']}" : ''),
                'country' => $country,
                'iso3' => null,
                'latitude' => $lat,
                'longitude' => $lng,
                'coordinates' => ($lat !== null && $lng !== null) ? [$lat, $lng] : null,
                'severity_level' => in_array($normalizedCategory, ['Tropical Cyclone', 'Severe Storm', 'Volcano']) ? 'Orange' : 'Green',
                'severity_score' => null,
                'severity_text' => 'NASA Monitored Event',
                'depth_km' => null,
                'event_time' => $eventTime,
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

        if ($lat !== null && $lng !== null) {
            if ($lat >= -11.5 && $lat <= 28.5 && $lng >= 92.0 && $lng <= 142.0) {
                return 'Southeast Asia';
            }
        }

        return null;
    }

    /**
     * Compute approximate centroid for polygon / multipolygon array.
     *
     * @param array<mixed> $coords
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
