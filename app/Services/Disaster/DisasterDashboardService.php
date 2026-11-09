<?php

namespace App\Services\Disaster;

use App\Models\DisasterReport;
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
     * Each source service manages its own fresh cache TTL and 24-hour stale fallback cache.
     *
     * @return array<string, mixed>
     */
    public function getDashboardData(bool $forceRefresh = false): array
    {
        $gdacs = $this->gdacsService->getEvents($forceRefresh);
        $usgs = $this->usgsService->getEvents($forceRefresh);
        $eonet = $this->eonetService->getEvents($forceRefresh);
        $weather = $this->openMeteoService->getWeather($forceRefresh);

        // Deduplicate and merge events across agencies
        $allEvents = array_merge($gdacs['events'], $usgs['events'], $eonet['events']);
        $deduplicatedEvents = $this->deduplicateEvents($allEvents);

        // Fetch verified community reports (never unverified/pending/rejected)
        try {
            $communityReports = DisasterReport::with('user:id,name')
                ->where('status', DisasterReport::STATUS_VERIFIED)
                ->get()
                ->map(fn (DisasterReport $report) => $report->toDisasterEvent())
                ->toArray();
        } catch (Throwable) {
            $communityReports = [];
        }

        // Merge verified community reports without polluting official provider data
        $mergedEvents = array_merge($deduplicatedEvents, $communityReports);

        // Sort events chronologically DESC (newest first)
        usort($mergedEvents, function ($a, $b) {
            return strcmp($b['event_time'] ?? '', $a['event_time'] ?? '');
        });

        // Compute summary counts from actual verified events
        $summary = $this->calculateSummary($mergedEvents, [
            'gdacs' => $gdacs['meta'],
            'usgs' => $usgs['meta'],
            'eonet' => $eonet['meta'],
            'weather' => $weather['meta'],
        ]);

        return [
            'generated_at' => Carbon::now()->toIso8601String(),
            'summary' => $summary,
            'events' => $mergedEvents,
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
                ['id' => 'other', 'label' => 'Other Incidents', 'icon' => 'alert-circle'],
            ],
        ];
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

    /**
     * Retrieve aggregated country-level disaster telemetry, KPI metrics, analytics, and capital weather.
     * Caches country-specific results for 5 minutes.
     *
     * @return array<string, mixed>
     */
    public function getCountryDashboardData(?string $country = null, bool $forceRefresh = false): array
    {
        $resolvedKey = $this->resolveAseanCountryKey($country);
        $isValidCountry = $resolvedKey !== null;
        $countryKey = $resolvedKey ?? 'All';

        $cacheKey = 'disaster_country_dashboard_' . strtolower(str_replace(['-', '_', ' '], '', $countryKey));

        if (!$forceRefresh && Cache::has($cacheKey)) {
            /** @var array<string, mixed> $cached */
            $cached = Cache::get($cacheKey);
            $cached['requested_country'] = $country;
            $cached['is_valid_country'] = $isValidCountry;

            return $cached;
        }

        $dashboardData = $this->getDashboardData($forceRefresh);

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
            if (!isset($byCategoryMap[$cat])) {
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

        Cache::put($cacheKey, $result, 300);

        return $result;
    }

    /**
     * Group verified events by date without inventing days or missing data.
     *
     * @param array<int, array<string, mixed>> $events
     * @return array<int, array<string, mixed>>
     */
    private function calculateHistoricalTrends(array $events): array
    {
        $groups = [];

        foreach ($events as $event) {
            $rawTime = $event['event_date'] ?? $event['event_time'] ?? null;
            if (!$rawTime) {
                continue;
            }

            try {
                $dateKey = Carbon::parse($rawTime)->format('Y-m-d');
                $formatted = Carbon::parse($rawTime)->format('M d, Y');
            } catch (Throwable) {
                continue;
            }

            if (!isset($groups[$dateKey])) {
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
     * Retrieve an individual disaster event by its ID or source ID, enriched with timeline updates.
     *
     * @return array<string, mixed>|null
     */
    public function getEventById(string $id, bool $forceRefresh = false): ?array
    {
        $id = trim($id);
        if ($id === '') {
            return null;
        }

        $dashboardData = $this->getDashboardData($forceRefresh);
        foreach ($dashboardData['events'] as $event) {
            if (
                ($event['id'] ?? '') === $id ||
                ($event['source_id'] ?? '') === $id ||
                strcasecmp((string) ($event['id'] ?? ''), $id) === 0
            ) {
                return $this->enrichEventWithTimeline($event);
            }
        }

        // Try fresh telemetry once if not forced yet and event was not in cache
        if (!$forceRefresh) {
            $dashboardData = $this->getDashboardData(true);
            foreach ($dashboardData['events'] as $event) {
                if (
                    ($event['id'] ?? '') === $id ||
                    ($event['source_id'] ?? '') === $id ||
                    strcasecmp((string) ($event['id'] ?? ''), $id) === 0
                ) {
                    return $this->enrichEventWithTimeline($event);
                }
            }
        }

        return null;
    }

    /**
     * Build a chronological timeline of verified updates for an event.
     * Never invents dates or events. If timeline data is unavailable, returns an empty array.
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public function enrichEventWithTimeline(array $event): array
    {
        if (!empty($event['is_community_report'])) {
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
            if (!empty($event['magnitude'])) {
                $desc = "Preliminary {$category} registered with magnitude M " . number_format($event['magnitude'], 1) . " by {$sourceName}.";
            } elseif (!empty($event['severity'])) {
                $desc = "Initial incident advisory issued by {$sourceName} ({$event['severity']}).";
            } else {
                $desc = "First recorded incident bulletin received from {$sourceName}.";
            }

            $key = $initialTime . '_' . $sourceName;
            $timeline[] = [
                'timestamp' => $initialTime,
                'title' => $title,
                'description' => $desc,
                'source' => $sourceName,
                'type' => 'detection',
            ];
            $seenKeys[$key] = true;
        }

        // 2. Multi-source observations (if NASA EONET provided multiple geometry entries)
        if (!empty($event['observations']) && is_array($event['observations'])) {
            foreach ($event['observations'] as $obs) {
                $obsDate = $obs['date'] ?? null;
                if ($obsDate !== null) {
                    try {
                        $iso = Carbon::parse($obsDate)->toIso8601String();
                        $key = $iso . '_NASA EONET_obs';
                        if (!isset($seenKeys[$key]) && $iso !== $initialTime) {
                            $desc = 'Satellite imagery observation recorded by NASA EONET sensors.';
                            if (!empty($obs['magnitude']) && !empty($obs['unit'])) {
                                $desc .= " Measured intensity: {$obs['magnitude']} {$obs['unit']}.";
                            }
                            $timeline[] = [
                                'timestamp' => $iso,
                                'title' => 'Satellite Sensor Observation',
                                'description' => $desc,
                                'source' => 'NASA EONET',
                                'type' => 'observation',
                            ];
                            $seenKeys[$key] = true;
                        }
                    } catch (Throwable) {
                        // Skip unparseable dates
                    }
                }
            }
        }

        // 3. Multi-source bulletins (if cross-corroborated across GDACS, USGS, or NASA)
        $sources = $event['sources'] ?? [$event['source']];
        if (count($sources) > 1) {
            foreach ($sources as $secSource) {
                if ($secSource !== $event['source']) {
                    $key = ($event['event_time'] ?? '') . '_' . $secSource;
                    if (!isset($seenKeys[$key])) {
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

        // 4. Last updated / revision time
        $updatedTime = $event['last_updated_time'] ?? null;
        if ($updatedTime !== null && $updatedTime !== $initialTime) {
            $key = $updatedTime . '_update';
            if (!isset($seenKeys[$key])) {
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
                    $unique[$index] = $this->mergeIncidentData($existing, $event);
                    $isDuplicate = true;
                    break;
                }
            }

            if (!$isDuplicate) {
                if (!isset($event['disaster_type'])) {
                    $event['disaster_type'] = $event['category'] ?? 'Other Disaster';
                }
                if (!isset($event['event_date'])) {
                    $event['event_date'] = $event['event_time'] ?? null;
                }
                if (!isset($event['location'])) {
                    $event['location'] = $event['country'] ?? null;
                }
                if (!isset($event['severity'])) {
                    $event['severity'] = $event['severity_text'] ?? $event['severity_level'] ?? null;
                }
                if (!isset($event['last_updated_time'])) {
                    $event['last_updated_time'] = null;
                }
                if (!isset($event['sources'])) {
                    $event['sources'] = [$event['source']];
                }
                if (!isset($event['source_urls'])) {
                    $event['source_urls'] = [
                        [
                            'source' => $event['source'],
                            'url' => $event['source_url'],
                        ],
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
        // Exact ID or (source + source_id) match
        if (!empty($a['id']) && !empty($b['id']) && $a['id'] === $b['id']) {
            return true;
        }
        if (
            !empty($a['source']) &&
            !empty($b['source']) &&
            $a['source'] === $b['source'] &&
            !empty($a['source_id']) &&
            !empty($b['source_id']) &&
            $a['source_id'] === $b['source_id']
        ) {
            return true;
        }

        // Must be compatible disaster category
        $catA = strtolower((string) ($a['category'] ?? $a['disaster_type'] ?? ''));
        $catB = strtolower((string) ($b['category'] ?? $b['disaster_type'] ?? ''));
        $isStormPair = in_array($catA, ['tropical cyclone', 'severe storm'], true)
            && in_array($catB, ['tropical cyclone', 'severe storm'], true);

        if ($catA !== $catB && !$isStormPair) {
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
        $timeA = $a['event_time'] ?? $a['event_date'] ?? null;
        $timeB = $b['event_time'] ?? $b['event_date'] ?? null;
        if ($timeA && $timeB) {
            try {
                $diffMinutes = abs(Carbon::parse($timeA)->diffInMinutes(Carbon::parse($timeB)));
                if ($catA === 'earthquake') {
                    // Earthquakes: within 80km and within 90 minutes
                    return $distanceKm <= 80 && $diffMinutes <= 90;
                }

                // Cyclones/Floods/Storms/Wildfires: within 100km and within 48 hours
                return $distanceKm <= 100 && $diffMinutes <= 2880;
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
        if (!in_array($secondary['source'], $sources, true)) {
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

        // Prefer higher severity level when supplied
        $severityOrder = ['Red' => 3, 'Orange' => 2, 'Green' => 1];
        $sevPrimary = $primary['severity_level'] ?? null;
        $sevSecondary = $secondary['severity_level'] ?? null;

        if (($severityOrder[$sevSecondary ?? ''] ?? 0) > ($severityOrder[$sevPrimary ?? ''] ?? 0)) {
            $merged['severity_level'] = $secondary['severity_level'];
            $merged['severity_text'] = $secondary['severity_text'] ?? $merged['severity_text'];
            $merged['severity'] = $secondary['severity'] ?? $merged['severity'];
        }

        // Fill missing values from secondary report without overwriting verified fields
        foreach (['depth_km', 'magnitude', 'country', 'location', 'iso3', 'description', 'severity', 'severity_text', 'observations'] as $field) {
            if (empty($merged[$field]) && !empty($secondary[$field])) {
                $merged[$field] = $secondary[$field];
            }
        }

        // Prefer more specific location over generic country name
        if (
            !empty($secondary['location']) &&
            ($merged['location'] === $merged['country'] || $merged['location'] === 'Southeast Asia')
        ) {
            $merged['location'] = $secondary['location'];
        }

        // Keep newest last_updated_time
        $updPrimary = $merged['last_updated_time'] ?? null;
        $updSecondary = $secondary['last_updated_time'] ?? null;
        if ($updSecondary !== null && ($updPrimary === null || $updSecondary > $updPrimary)) {
            $merged['last_updated_time'] = $updSecondary;
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
            $cat = strtolower((string) ($ev['category'] ?? $ev['disaster_type'] ?? ''));
            $sev = $ev['severity_level'] ?? null;

            if (in_array($sev, ['Orange', 'Red'], true)) {
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

        $resolveGroupStatus = static function (array $statuses): string {
            $allUnavailable = true;
            $anyLive = false;
            foreach ($statuses as $st) {
                if ($st !== 'Unavailable') {
                    $allUnavailable = false;
                }
                if ($st === 'Live') {
                    $anyLive = true;
                }
            }

            if ($allUnavailable) {
                return 'Unavailable';
            }

            return $anyLive ? 'Active' : 'Stale';
        };

        return [
            'total_active_alerts' => [
                'count' => $activeAlerts,
                'status' => $resolveGroupStatus([$gdacsStatus, $usgsStatus]),
                'description' => 'Orange & Red Alert incidents requiring urgent regional attention',
            ],
            'earthquakes' => [
                'count' => $earthquakes,
                'status' => $resolveGroupStatus([$usgsStatus, $gdacsStatus]),
                'description' => 'Real-time seismic events registered in Southeast Asia',
            ],
            'tropical_cyclones' => [
                'count' => $cyclones,
                'status' => $resolveGroupStatus([$gdacsStatus, $eonetStatus]),
                'description' => 'Tracked tropical depressions, storms, and typhoons',
            ],
            'floods' => [
                'count' => $floods,
                'status' => $resolveGroupStatus([$gdacsStatus, $eonetStatus]),
                'description' => 'Riverine & flash flood events reported in the region',
            ],
            'wildfires' => [
                'count' => $wildfires,
                'status' => $resolveGroupStatus([$eonetStatus, $gdacsStatus]),
                'description' => 'Thermal hotspots & forest fires tracked via satellite telemetry',
            ],
            'volcanoes' => [
                'count' => $volcanoes,
                'status' => $resolveGroupStatus([$eonetStatus, $gdacsStatus]),
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
