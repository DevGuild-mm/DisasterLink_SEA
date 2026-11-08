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
    private const CACHE_TTL_SECONDS = 180; // 3 minutes

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

    /**
     * Retrieve USGS earthquakes filtered to Southeast Asia.
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
            // 1. Fetch all_hour.geojson as requested
            $hourUrl = 'https://earthquake.usgs.gov/earthquakes/feed/v1.0/summary/all_hour.geojson';
            $hourResponse = Http::withoutVerifying()->timeout(6)->withHeaders([
                'Accept' => 'application/json',
                'User-Agent' => 'DisasterLinkSEA/1.0',
            ])->get($hourUrl);

            $featuresHour = $hourResponse->successful() ? ($hourResponse->json()['features'] ?? []) : [];
            $eventsHour = $this->filterAndNormalizeFeatures($featuresHour);

            // 2. Also fetch all_day.geojson or all_week.geojson to ensure recent SE Asia seismic activity is represented
            $weekUrl = 'https://earthquake.usgs.gov/earthquakes/feed/v1.0/summary/all_week.geojson';
            $weekResponse = Http::withoutVerifying()->timeout(6)->withHeaders([
                'Accept' => 'application/json',
                'User-Agent' => 'DisasterLinkSEA/1.0',
            ])->get($weekUrl);

            $featuresWeek = $weekResponse->successful() ? ($weekResponse->json()['features'] ?? []) : [];
            $eventsWeek = $this->filterAndNormalizeFeatures($featuresWeek);

            // Merge with hour events taking precedence (deduplicate by source_id)
            $allEventsMap = [];
            foreach ($eventsHour as $ev) {
                $allEventsMap[$ev['source_id']] = $ev;
            }
            foreach ($eventsWeek as $ev) {
                if (!isset($allEventsMap[$ev['source_id']])) {
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

            $latestEventTime = !empty($finalEvents) ? ($finalEvents[0]['event_time'] ?? null) : null;

            $meta = [
                'source' => 'USGS',
                'name' => 'United States Geological Survey',
                'status' => 'Live',
                'last_successful_fetch' => Carbon::now()->toIso8601String(),
                'latest_event_time' => $latestEventTime,
                'total_features_fetched' => count($featuresHour) + count($featuresWeek),
                'sea_events_count' => count($finalEvents),
                'is_fresh' => true,
                'is_stale' => false,
                'documentation_url' => 'https://earthquake.usgs.gov/earthquakes/feed/v1.0/geojson.php',
            ];

            Cache::put(self::CACHE_KEY, $finalEvents, self::CACHE_TTL_SECONDS);
            Cache::put(self::CACHE_META_KEY, $meta, self::CACHE_TTL_SECONDS);

            return [
                'events' => $finalEvents,
                'meta' => $meta,
            ];
        } catch (Throwable $e) {
            Log::error('USGS API fetch error: ' . $e->getMessage());
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

        return [
            'events' => [],
            'meta' => [
                'source' => 'USGS',
                'name' => 'United States Geological Survey',
                'status' => 'Unavailable',
                'last_successful_fetch' => null,
                'latest_event_time' => null,
                'total_features_fetched' => 0,
                'sea_events_count' => 0,
                'is_fresh' => false,
                'is_stale' => false,
                'error' => 'Unable to contact USGS server.',
                'documentation_url' => 'https://earthquake.usgs.gov/earthquakes/feed/v1.0/geojson.php',
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $features
     * @return array<int, array<string, mixed>>
     */
    private function filterAndNormalizeFeatures(array $features): array
    {
        $events = [];

        foreach ($features as $f) {
            $geom = $f['geometry'] ?? [];
            $coords = $geom['coordinates'] ?? [];
            if (!is_array($coords) || count($coords) < 2) {
                continue;
            }

            $lng = (float) $coords[0];
            $lat = (float) $coords[1];
            $depthKm = isset($coords[2]) ? (float) $coords[2] : null;

            // Geographic bounding box filter for Southeast Asia
            if ($lat < self::MIN_LAT || $lat > self::MAX_LAT || $lng < self::MIN_LNG || $lng > self::MAX_LNG) {
                continue;
            }

            $props = $f['properties'] ?? [];
            $place = trim((string) ($props['place'] ?? ''));
            $country = $this->detectCountryFromPlace($place, $lat, $lng);

            $id = (string) ($f['id'] ?? uniqid());
            $mag = isset($props['mag']) ? (float) $props['mag'] : null;

            // Severity level mapping based on magnitude
            $severityLevel = null;
            if ($mag !== null) {
                if ($mag >= 6.5) {
                    $severityLevel = 'Red';
                } elseif ($mag >= 5.0) {
                    $severityLevel = 'Orange';
                } else {
                    $severityLevel = 'Green';
                }
            }

            // Timestamp in milliseconds -> ISO8601
            $timeMs = $props['time'] ?? null;
            $eventTime = null;
            if (is_numeric($timeMs)) {
                $eventTime = Carbon::createFromTimestampMs((int) $timeMs)->toIso8601String();
            }

            $title = trim((string) ($props['title'] ?? ($mag !== null ? "M {$mag} Earthquake" : 'Earthquake')));
            $url = $props['url'] ?? "https://earthquake.usgs.gov/earthquakes/event/{$id}";

            $events[] = [
                'id' => 'usgs_' . $id,
                'source' => 'USGS',
                'source_id' => $id,
                'category' => 'Earthquake',
                'raw_type' => 'earthquake',
                'title' => $title,
                'description' => $place . ($depthKm !== null ? " | Depth: {$depthKm} km" : ''),
                'country' => $country,
                'iso3' => null,
                'latitude' => $lat,
                'longitude' => $lng,
                'coordinates' => [$lat, $lng],
                'magnitude' => $mag,
                'depth_km' => $depthKm,
                'severity_level' => $severityLevel,
                'severity_score' => $mag,
                'severity_text' => $mag !== null ? "Magnitude {$mag}M" : null,
                'event_time' => $eventTime,
                'source_url' => $url,
            ];
        }

        return $events;
    }

    private function detectCountryFromPlace(string $place, float $lat, float $lng): string
    {
        $lower = strtolower($place);
        foreach (self::ASEAN_KEYWORDS as $keyword => $country) {
            if (str_contains($lower, $keyword)) {
                return $country;
            }
        }

        // Check regional coordinates if place string is vague (e.g. "Celebes Sea", "Java Sea", "Molucca Sea")
        if (str_contains($lower, 'celebes') || str_contains($lower, 'java') || str_contains($lower, 'molucca') || str_contains($lower, 'flores') || str_contains($lower, 'banda')) {
            return 'Indonesia';
        }
        if (str_contains($lower, 'luzon') || str_contains($lower, 'mindanao') || str_contains($lower, 'visayas') || str_contains($lower, 'sulu')) {
            return 'Philippines';
        }
        if (str_contains($lower, 'andaman')) {
            return 'Myanmar';
        }
        if (str_contains($lower, 'gulf of thailand')) {
            return 'Thailand';
        }

        // Fallback: extract after comma or return Southeast Asia
        $parts = explode(',', $place);
        if (count($parts) > 1) {
            $candidate = trim(end($parts));
            if (!empty($candidate)) {
                return $candidate;
            }
        }

        return 'Southeast Asia';
    }
}
