<?php

namespace App\Services\Disaster;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

class DisasterDashboardService
{
    public const ASEAN_COUNTRIES = [
        'All' => [
            'name' => 'All Southeast Asia',
            'code' => 'ALL',
            'iso3' => 'ALL',
            'center' => [10.0, 108.0],
            'zoom' => 5,
            'bounds' => [[-11.5, 92.0], [28.5, 142.0]],
        ],
        'Brunei' => [
            'name' => 'Brunei',
            'code' => 'BN',
            'iso3' => 'BRN',
            'center' => [4.5353, 114.7277],
            'zoom' => 8,
            'bounds' => [[4.0, 114.0], [5.1, 115.4]],
        ],
        'Cambodia' => [
            'name' => 'Cambodia',
            'code' => 'KH',
            'iso3' => 'KHM',
            'center' => [12.5657, 104.9910],
            'zoom' => 7,
            'bounds' => [[10.4, 102.3], [14.7, 107.6]],
        ],
        'Indonesia' => [
            'name' => 'Indonesia',
            'code' => 'ID',
            'iso3' => 'IDN',
            'center' => [-0.7893, 113.9213],
            'zoom' => 5,
            'bounds' => [[-11.0, 95.0], [6.1, 141.0]],
        ],
        'Laos' => [
            'name' => 'Laos',
            'code' => 'LA',
            'iso3' => 'LAO',
            'center' => [19.8563, 102.4955],
            'zoom' => 6,
            'bounds' => [[13.9, 100.1], [22.5, 107.7]],
        ],
        'Malaysia' => [
            'name' => 'Malaysia',
            'code' => 'MY',
            'iso3' => 'MYS',
            'center' => [4.2105, 101.9758],
            'zoom' => 6,
            'bounds' => [[0.8, 99.6], [7.4, 119.3]],
        ],
        'Myanmar' => [
            'name' => 'Myanmar',
            'code' => 'MM',
            'iso3' => 'MMR',
            'center' => [21.9162, 95.9560],
            'zoom' => 6,
            'bounds' => [[9.6, 92.2], [28.5, 101.2]],
        ],
        'Philippines' => [
            'name' => 'Philippines',
            'code' => 'PH',
            'iso3' => 'PHL',
            'center' => [12.8797, 121.7740],
            'zoom' => 6,
            'bounds' => [[4.6, 116.9], [21.1, 126.6]],
        ],
        'Singapore' => [
            'name' => 'Singapore',
            'code' => 'SG',
            'iso3' => 'SGP',
            'center' => [1.3521, 103.8198],
            'zoom' => 11,
            'bounds' => [[1.15, 103.6], [1.48, 104.1]],
        ],
        'Thailand' => [
            'name' => 'Thailand',
            'code' => 'TH',
            'iso3' => 'THA',
            'center' => [15.8700, 100.9925],
            'zoom' => 6,
            'bounds' => [[5.6, 97.3], [20.5, 105.6]],
        ],
        'Timor-Leste' => [
            'name' => 'Timor-Leste',
            'code' => 'TL',
            'iso3' => 'TLS',
            'center' => [-8.8742, 125.7275],
            'zoom' => 8,
            'bounds' => [[-9.5, 124.0], [-8.1, 127.3]],
        ],
        'Vietnam' => [
            'name' => 'Vietnam',
            'code' => 'VN',
            'iso3' => 'VNM',
            'center' => [14.0583, 108.2772],
            'zoom' => 6,
            'bounds' => [[8.5, 102.1], [23.4, 109.5]],
        ],
    ];

    public function __construct(
        protected GdacsService $gdacsService,
        protected UsgsService $usgsService,
        protected NasaEonetService $eonetService,
        protected OpenMeteoService $openMeteoService,
    ) {
    }

    /**
     * Retrieve the aggregated real-time disaster dashboard data.
     *
     * @return array<string, mixed>
     */
    public function getDashboardData(bool $forceRefresh = false): array
    {
        $cacheKey = 'disaster_dashboard_full_payload';

        if (!$forceRefresh) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $gdacs = $this->gdacsService->getEvents();
        $usgs = $this->usgsService->getEvents();
        $eonet = $this->eonetService->getEvents();
        $weather = $this->openMeteoService->getWeather();

        // Deduplicate and merge events
        $allEvents = array_merge($gdacs['events'], $usgs['events'], $eonet['events']);
        $deduplicatedEvents = $this->deduplicateEvents($allEvents);

        // Sort events chronologically DESC (newest first)
        usort($deduplicatedEvents, function ($a, $b) {
            return strcmp($b['event_time'] ?? '', $a['event_time'] ?? '');
        });

        // Compute summary counts from actual verified events
        $summary = $this->calculateSummary($deduplicatedEvents, [
            'gdacs' => $gdacs['meta'],
            'usgs' => $usgs['meta'],
            'eonet' => $eonet['meta'],
            'weather' => $weather['meta'],
        ]);

        $payload = [
            'generated_at' => Carbon::now()->toIso8601String(),
            'summary' => $summary,
            'events' => $deduplicatedEvents,
            'weather' => $weather['weather'],
            'sources' => [
                'gdacs' => $gdacs['meta'],
                'usgs' => $usgs['meta'],
                'eonet' => $eonet['meta'],
                'weather' => $weather['meta'],
            ],
            'countries' => self::ASEAN_COUNTRIES,
            'categories' => [
                ['id' => 'all', 'label' => 'All Categories', 'icon' => 'globe'],
                ['id' => 'earthquake', 'label' => 'Earthquakes', 'icon' => 'activity'],
                ['id' => 'cyclone', 'label' => 'Tropical Cyclones', 'icon' => 'wind'],
                ['id' => 'flood', 'label' => 'Floods', 'icon' => 'waves'],
                ['id' => 'wildfire', 'label' => 'Wildfires', 'icon' => 'flame'],
                ['id' => 'volcano', 'label' => 'Volcanoes', 'icon' => 'triangle-alert'],
                ['id' => 'storm', 'label' => 'Severe Storms', 'icon' => 'cloud-lightning'],
            ],
        ];

        Cache::put($cacheKey, $payload, 60);

        return $payload;
    }

    /**
     * Deduplicate events reported across multiple agencies (e.g., USGS and GDACS both reporting an earthquake).
     *
     * @param array<int, array<string, mixed>> $events
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateEvents(array $events): array
    {
        /** @var array<int, array<string, mixed>> $unique */
        $unique = [];

        foreach ($events as $event) {
            $isDuplicate = false;

            foreach ($unique as $index => $existing) {
                if ($this->isSameIncident($event, $existing)) {
                    // Merge complementary information
                    $unique[$index] = $this->mergeIncidentData($existing, $event);
                    $isDuplicate = true;
                    break;
                }
            }

            if (!$isDuplicate) {
                // Ensure sources array is initialized
                if (!isset($event['sources'])) {
                    $event['sources'] = [$event['source']];
                }
                if (!isset($event['source_urls'])) {
                    $event['source_urls'] = [
                        [
                            'source' => $event['source'],
                            'url' => $event['source_url'],
                        ]
                    ];
                }
                $unique[] = $event;
            }
        }

        return $unique;
    }

    /**
     * Determine if two events describe the same physical incident.
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private function isSameIncident(array $a, array $b): bool
    {
        // Must be same category
        $catA = strtolower((string) ($a['category'] ?? ''));
        $catB = strtolower((string) ($b['category'] ?? ''));
        if ($catA !== $catB) {
            return false;
        }

        // Coordinates check
        $latA = $a['latitude'] ?? null;
        $lngA = $a['longitude'] ?? null;
        $latB = $b['latitude'] ?? null;
        $lngB = $b['longitude'] ?? null;

        if ($latA === null || $lngA === null || $latB === null || $lngB === null) {
            return false;
        }

        $distanceKm = $this->haversineDistance((float) $latA, (float) $lngA, (float) $latB, (float) $lngB);

        // Time difference check
        $timeA = $a['event_time'] ?? null;
        $timeB = $b['event_time'] ?? null;
        if ($timeA && $timeB) {
            try {
                $diffMinutes = abs(Carbon::parse($timeA)->diffInMinutes(Carbon::parse($timeB)));
                if ($catA === 'earthquake') {
                    // Earthquakes: within 80km and within 90 minutes
                    return ($distanceKm <= 80 && $diffMinutes <= 90);
                }

                // Floods/Storms/Wildfires: within 100km and within 48 hours
                return ($distanceKm <= 100 && $diffMinutes <= 2880);
            } catch (Throwable) {
                // Ignore parse errors
            }
        }

        return false;
    }

    /**
     * Merge complementary data from two matching incident reports.
     *
     * @param array<string, mixed> $primary
     * @param array<string, mixed> $secondary
     * @return array<string, mixed>
     */
    private function mergeIncidentData(array $primary, array $secondary): array
    {
        $merged = $primary;

        // Merge sources list
        $sources = $primary['sources'] ?? [$primary['source']];
        if (!in_array($secondary['source'], $sources)) {
            $sources[] = $secondary['source'];
        }
        $merged['sources'] = $sources;

        // Merge source URLs
        $urls = $primary['source_urls'] ?? [['source' => $primary['source'], 'url' => $primary['source_url']]];
        $exists = false;
        foreach ($urls as $u) {
            if ($u['source'] === $secondary['source']) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $urls[] = ['source' => $secondary['source'], 'url' => $secondary['source_url']];
        }
        $merged['source_urls'] = $urls;

        // Prefer higher severity level
        $severityOrder = ['Red' => 3, 'Orange' => 2, 'Green' => 1];
        $sevPrimary = $primary['severity_level'] ?? 'Green';
        $sevSecondary = $secondary['severity_level'] ?? 'Green';

        if (($severityOrder[$sevSecondary] ?? 0) > ($severityOrder[$sevPrimary] ?? 0)) {
            $merged['severity_level'] = $secondary['severity_level'];
            $merged['severity_text'] = $secondary['severity_text'];
        }

        // Fill missing values
        if (empty($merged['depth_km']) && !empty($secondary['depth_km'])) {
            $merged['depth_km'] = $secondary['depth_km'];
        }
        if (empty($merged['magnitude']) && !empty($secondary['magnitude'])) {
            $merged['magnitude'] = $secondary['magnitude'];
        }
        if (empty($merged['country']) && !empty($secondary['country'])) {
            $merged['country'] = $secondary['country'];
        }

        return $merged;
    }

    /**
     * Calculate summary statistics from real active data.
     *
     * @param array<int, array<string, mixed>> $events
     * @param array<string, array<string, mixed>> $sourceMetas
     * @return array<string, mixed>
     */
    private function calculateSummary(array $events, array $sourceMetas): array
    {
        $activeAlerts = 0;
        $earthquakes = 0;
        $cyclones = 0;
        $floods = 0;
        $wildfires = 0;
        $volcanoes = 0;

        foreach ($events as $ev) {
            $cat = strtolower((string) ($ev['category'] ?? ''));
            $sev = $ev['severity_level'] ?? null;

            if (in_array($sev, ['Orange', 'Red'])) {
                $activeAlerts++;
            }

            match ($cat) {
                'earthquake' => $earthquakes++,
                'tropical cyclone', 'severe storm' => $cyclones++,
                'flood' => $floods++,
                'wildfire' => $wildfires++,
                'volcano' => $volcanoes++,
                default => null,
            };
        }

        // Source availability flags
        $gdacsStatus = $sourceMetas['gdacs']['status'] ?? 'Unavailable';
        $usgsStatus = $sourceMetas['usgs']['status'] ?? 'Unavailable';
        $eonetStatus = $sourceMetas['eonet']['status'] ?? 'Unavailable';

        return [
            'total_active_alerts' => [
                'count' => $activeAlerts,
                'status' => ($gdacsStatus === 'Unavailable' && $usgsStatus === 'Unavailable') ? 'Unavailable' : 'Active',
                'description' => 'Orange & Red Alert incidents requiring urgent regional attention',
            ],
            'earthquakes' => [
                'count' => $earthquakes,
                'status' => $usgsStatus === 'Unavailable' ? 'Unavailable' : 'Active',
                'description' => 'Real-time seismic events registered in Southeast Asia',
            ],
            'tropical_cyclones' => [
                'count' => $cyclones,
                'status' => $gdacsStatus === 'Unavailable' ? 'Unavailable' : 'Active',
                'description' => 'Tracked tropical depressions, storms, and typhoons',
            ],
            'floods' => [
                'count' => $floods,
                'status' => ($gdacsStatus === 'Unavailable' && $eonetStatus === 'Unavailable') ? 'Unavailable' : 'Active',
                'description' => 'Riverine & flash flood events reported in the region',
            ],
            'wildfires' => [
                'count' => $wildfires,
                'status' => ($eonetStatus === 'Unavailable' && $gdacsStatus === 'Unavailable') ? 'Unavailable' : 'Active',
                'description' => 'Thermal hotspots & forest fires tracked via satellite telemetry',
            ],
            'volcanoes' => [
                'count' => $volcanoes,
                'status' => $eonetStatus === 'Unavailable' ? 'Unavailable' : 'Active',
                'description' => 'Volcanic activity & ash cloud warnings',
            ],
            'total_events' => count($events),
        ];
    }

    /**
     * Compute Haversine distance in kilometers between two lat/lng coordinates.
     */
    private function haversineDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371; // km
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
