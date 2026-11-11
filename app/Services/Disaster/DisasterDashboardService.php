<?php

namespace App\Services\Disaster;

use App\Services\Disaster\Dashboard\DashboardQueryService;
use Carbon\Carbon;
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
        protected DashboardQueryService $queries,
    ) {}

    /**
     * The dashboard payload, read from MySQL through the cache. Never contacts GDACS, USGS or Open-Meteo.
     *
     * @param  bool  $reload  rebuild from MySQL now (the public "Reload data")
     * @return array<string, mixed>
     */
    public function getDashboardData(bool $reload = false): array
    {
        return $this->queries->dashboard($reload);
    }

    /**
     * Resolve a raw country slug, name, or code into the canonical ASEAN country key.
     */
    public function resolveAseanCountryKey(?string $input): ?string
    {
        if ($input === null || trim($input) === '') {
            return 'All';
        }

        $trimmed = trim($input);
        if (strcasecmp($trimmed, 'all') === 0 || strcasecmp($trimmed, 'all southeast asia') === 0) {
            return 'All';
        }

        $normalized = strtolower(str_replace(['-', '_', ' '], '', $trimmed));

        // Common country aliases and alternate geopolitical names
        $aliases = [
            'burma' => 'Myanmar',
            'laopdr' => 'Laos',
            'laos' => 'Laos',
            'easttimor' => 'Timor-Leste',
            'timorleste' => 'Timor-Leste',
            'bruneidarussalam' => 'Brunei',
        ];

        if (isset($aliases[$normalized])) {
            return $aliases[$normalized];
        }

        foreach (self::ASEAN_COUNTRIES as $key => $meta) {
            $keyNorm = strtolower(str_replace(['-', '_', ' '], '', $key));
            $nameNorm = strtolower(str_replace(['-', '_', ' '], '', $meta['name']));
            $codeNorm = strtolower($meta['code']);
            $isoNorm = strtolower($meta['iso3']);

            if (
                $normalized === $keyNorm ||
                $normalized === $nameNorm ||
                $normalized === $codeNorm ||
                $normalized === $isoNorm
            ) {
                return $key;
            }
        }

        return null;
    }

    public function getCountryDashboardData(?string $country = null, bool $reload = false): array
    {
        $resolvedKey = $this->resolveAseanCountryKey($country);
        $isValidCountry = $resolvedKey !== null;
        $countryKey = $resolvedKey ?? 'All';

        $dashboardData = $this->getDashboardData($reload);

        // Filter events by country
        if ($countryKey === 'All') {
            $countryEvents = $dashboardData['events'];
        } else {
            $countryEvents = array_values(array_filter($dashboardData['events'], function ($e) use ($countryKey) {
                return strcasecmp($e['country'] ?? '', $countryKey) === 0;
            }));
        }

        // Filter weather: capital city for the country (or all 11 capital cities if All)
        if ($countryKey === 'All') {
            $countryWeather = $dashboardData['weather'];
        } else {
            $countryWeather = array_values(array_filter($dashboardData['weather'], function ($w) use ($countryKey) {
                return strcasecmp($w['country'] ?? '', $countryKey) === 0;
            }));
        }

        // Calculate severity metrics
        $highAlerts = 0;
        $redAlerts = 0;
        $orangeAlerts = 0;
        $greenAlerts = 0;
        $unclassified = 0;

        foreach ($countryEvents as $e) {
            $sev = $e['severity_level'] ?? null;
            if ($sev === 'Red') {
                $redAlerts++;
                $highAlerts++;
            } elseif ($sev === 'Orange') {
                $orangeAlerts++;
                $highAlerts++;
            } elseif ($sev === 'Green') {
                $greenAlerts++;
            } else {
                $unclassified++;
            }
        }

        // Analytics 1: Events by Category
        $byCategoryMap = [];
        foreach ($countryEvents as $e) {
            $cat = (string) ($e['category'] ?? $e['disaster_type'] ?? 'Other');
            if (! isset($byCategoryMap[$cat])) {
                $byCategoryMap[$cat] = [
                    'category' => $cat,
                    'total_events' => 0,
                    'high_alerts' => 0,
                    'color' => match (strtolower($cat)) {
                        'earthquake' => '#f97316',
                        'tropical cyclone', 'severe storm' => '#06b6d4',
                        'flood' => '#3b82f6',
                        'wildfire' => '#e11d48',
                        'volcano' => '#a855f7',
                        'drought' => '#eab308',
                        default => '#64748b',
                    },
                ];
            }
            $byCategoryMap[$cat]['total_events']++;
            if (in_array($e['severity_level'] ?? '', ['Red', 'Orange'], true)) {
                $byCategoryMap[$cat]['high_alerts']++;
            }
        }
        // Sort by total events DESC
        usort($byCategoryMap, fn ($a, $b) => $b['total_events'] <=> $a['total_events']);
        $byCategoryList = array_values($byCategoryMap);

        // Analytics 2: Events by Country (Comparative distribution across all 11 ASEAN nations)
        $allAseanCountryNames = [
            'Brunei', 'Cambodia', 'Indonesia', 'Laos', 'Malaysia',
            'Myanmar', 'Philippines', 'Singapore', 'Thailand', 'Timor-Leste', 'Vietnam',
        ];

        $byCountry = [];
        foreach ($allAseanCountryNames as $cName) {
            $byCountry[$cName] = [
                'country' => $cName,
                'code' => self::ASEAN_COUNTRIES[$cName]['code'] ?? '',
                'iso3' => self::ASEAN_COUNTRIES[$cName]['iso3'] ?? '',
                'total_events' => 0,
                'high_alerts' => 0,
            ];
        }

        $unknownCountryCount = 0;
        foreach ($dashboardData['events'] as $e) {
            $c = $e['country'] ?? null;
            if ($c && isset($byCountry[$c])) {
                $byCountry[$c]['total_events']++;
                if (in_array($e['severity_level'] ?? '', ['Red', 'Orange'], true)) {
                    $byCountry[$c]['high_alerts']++;
                }
            } else {
                $unknownCountryCount++;
            }
        }

        $byCountryList = array_values($byCountry);
        if ($unknownCountryCount > 0) {
            $byCountryList[] = [
                'country' => 'Regional / Unattributed',
                'code' => 'REG',
                'iso3' => 'REG',
                'total_events' => $unknownCountryCount,
                'high_alerts' => 0,
            ];
        }

        // Analytics 3: Historical trends (chronological grouping of verified incidents)
        $historicalTrends = $this->calculateHistoricalTrends($countryEvents);

        $result = [
            'generated_at' => Carbon::now()->toIso8601String(),
            'selected_country' => $countryKey,
            'is_valid_country' => $isValidCountry,
            'requested_country' => $country,
            'country_meta' => self::ASEAN_COUNTRIES[$countryKey] ?? self::ASEAN_COUNTRIES['All'],
            'all_countries' => self::ASEAN_COUNTRIES,
            'summary' => [
                'total_events' => count($countryEvents),
                'high_alerts_count' => $highAlerts,
                'red_alerts_count' => $redAlerts,
                'orange_alerts_count' => $orangeAlerts,
                'green_alerts_count' => $greenAlerts,
                'unclassified_count' => $unclassified,
                'categories_count' => count($byCategoryList),
            ],
            'events' => $countryEvents,
            'weather' => $countryWeather,
            'analytics' => [
                'by_category' => $byCategoryList,
                'by_country' => $byCountryList,
                'historical_trends' => $historicalTrends,
            ],
            'sources' => $dashboardData['sources'],
        ];

        return $result;
    }

    /**
     * Group verified events by date without inventing days or missing data.
     *
     * @param  array<int, array<string, mixed>>  $events
     * @return array<int, array<string, mixed>>
     */
    private function calculateHistoricalTrends(array $events): array
    {
        $groups = [];

        foreach ($events as $event) {
            $rawTime = $event['event_date'] ?? $event['event_time'] ?? null;
            if (! $rawTime) {
                continue;
            }

            try {
                $dateKey = Carbon::parse($rawTime)->format('Y-m-d');
                $formatted = Carbon::parse($rawTime)->format('M d, Y');
            } catch (Throwable) {
                continue;
            }

            if (! isset($groups[$dateKey])) {
                $groups[$dateKey] = [
                    'date' => $dateKey,
                    'formatted_date' => $formatted,
                    'total_events' => 0,
                    'high_alerts' => 0,
                    'categories' => [],
                    'severities' => [],
                ];
            }

            $groups[$dateKey]['total_events']++;
            $cat = (string) ($event['category'] ?? $event['disaster_type'] ?? 'Other');
            $groups[$dateKey]['categories'][$cat] = ($groups[$dateKey]['categories'][$cat] ?? 0) + 1;

            $sev = (string) ($event['severity_level'] ?? 'Unclassified');
            if (in_array($sev, ['Red', 'Orange'], true)) {
                $groups[$dateKey]['high_alerts']++;
            }
            $groups[$dateKey]['severities'][$sev] = ($groups[$dateKey]['severities'][$sev] ?? 0) + 1;
        }

        // Sort chronologically (oldest to newest)
        ksort($groups);

        return array_values($groups);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getEventById(string $id): ?array
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }

        $event = $this->queries->eventById($id);

        if ($event !== null) {
            return $this->enrichEventWithTimeline($event);
        }

        return null;
    }

    /**
     * Build a chronological timeline of verified updates for an event.
     * Never invents dates or events. If timeline data is unavailable, returns an empty array.
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function enrichEventWithTimeline(array $event): array
    {
        if (! empty($event['is_community_report'])) {
            return $event;
        }

        $timeline = [];
        $seenKeys = [];

        // 1. Initial event detection/occurrence
        $initialTime = $event['event_date'] ?? $event['event_time'] ?? null;
        if ($initialTime !== null) {
            $sourceName = $event['source'] ?? 'Official Alert Feed';
            $category = $event['category'] ?? $event['disaster_type'] ?? 'Disaster';
            $title = "Initial {$category} Detection";
            if (! empty($event['magnitude'])) {
                $desc = "Preliminary {$category} registered with magnitude M ".number_format($event['magnitude'], 1)." by {$sourceName}.";
            } elseif (! empty($event['severity'])) {
                $desc = "Initial incident advisory issued by {$sourceName} ({$event['severity']}).";
            } else {
                $desc = "First recorded incident bulletin received from {$sourceName}.";
            }

            $key = $initialTime.'_'.$sourceName;
            $timeline[] = [
                'timestamp' => $initialTime,
                'title' => $title,
                'description' => $desc,
                'source' => $sourceName,
                'type' => 'detection',
            ];
            $seenKeys[$key] = true;
        }

        // 2. Multi-source bulletins (an event reported by both GDACS and USGS)
        $sources = $event['sources'] ?? [$event['source']];
        if (count($sources) > 1) {
            foreach ($sources as $secSource) {
                if ($secSource !== $event['source']) {
                    $key = ($event['event_time'] ?? '').'_'.$secSource;
                    if (! isset($seenKeys[$key])) {
                        $timeline[] = [
                            'timestamp' => $event['event_time'] ?? $initialTime,
                            'title' => "Cross-Agency Verification ({$secSource})",
                            'description' => "Incident cross-referenced and corroborated with official {$secSource} telemetry.",
                            'source' => $secSource,
                            'type' => 'verification',
                        ];
                        $seenKeys[$key] = true;
                    }
                }
            }
        }

        // 3. Last updated / revision time
        $updatedTime = $event['last_updated_time'] ?? null;
        if ($updatedTime !== null && $updatedTime !== $initialTime) {
            $key = $updatedTime.'_update';
            if (! isset($seenKeys[$key])) {
                $sourceName = $event['source'] ?? 'Official Agency';
                $timeline[] = [
                    'timestamp' => $updatedTime,
                    'title' => 'Latest Official Bulletin Revision',
                    'description' => "Updated bulletin, hypocenter recalculation, or situation assessment published by {$sourceName}.",
                    'source' => $sourceName,
                    'type' => 'update',
                ];
                $seenKeys[$key] = true;
            }
        }

        // Sort timeline chronologically (oldest first)
        usort($timeline, function ($a, $b) {
            return strcmp($a['timestamp'] ?? '', $b['timestamp'] ?? '');
        });

        $event['timeline'] = $timeline;

        return $event;
    }
}
