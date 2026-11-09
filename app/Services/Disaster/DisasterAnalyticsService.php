<?php

namespace App\Services\Disaster;

use Carbon\Carbon;

class DisasterAnalyticsService
{
    public const ASEAN_COUNTRIES = [
        'Brunei' => [
            'code' => 'BN',
            'iso3' => 'BRN',
            'capital' => 'Bandar Seri Begawan',
            'ndma' => 'National Disaster Management Centre (NDMC)',
            'hotline' => '991 / +673 238 0199',
        ],
        'Cambodia' => [
            'code' => 'KH',
            'iso3' => 'KHM',
            'capital' => 'Phnom Penh',
            'ndma' => 'National Committee for Disaster Management (NCDM)',
            'hotline' => '1299 / 117',
        ],
        'Indonesia' => [
            'code' => 'ID',
            'iso3' => 'IDN',
            'capital' => 'Jakarta',
            'ndma' => 'Badan Nasional Penanggulangan Bencana (BNPB)',
            'hotline' => '117 / +62 21 51010 117',
        ],
        'Laos' => [
            'code' => 'LA',
            'iso3' => 'LAO',
            'capital' => 'Vientiane',
            'ndma' => 'Department of Disaster Management (DDM / MLSW)',
            'hotline' => '1190 / +856 21 213 005',
        ],
        'Malaysia' => [
            'code' => 'MY',
            'iso3' => 'MYS',
            'capital' => 'Kuala Lumpur',
            'ndma' => 'Agensi Pengurusan Bencana Negara (NADMA)',
            'hotline' => '999 / +60 3 8870 4800',
        ],
        'Myanmar' => [
            'code' => 'MM',
            'iso3' => 'MMR',
            'capital' => 'Naypyidaw',
            'ndma' => 'Department of Disaster Management (DDM / MSWRR)',
            'hotline' => '067 3404666 / 067 3404777',
        ],
        'Philippines' => [
            'code' => 'PH',
            'iso3' => 'PHL',
            'capital' => 'Manila',
            'ndma' => 'NDRRMC / Office of Civil Defense (OCD)',
            'hotline' => '911 / (02) 8911-1406',
        ],
        'Singapore' => [
            'code' => 'SG',
            'iso3' => 'SGP',
            'capital' => 'Singapore',
            'ndma' => 'Singapore Civil Defence Force (SCDF)',
            'hotline' => '995 / 1800 280 0000',
        ],
        'Thailand' => [
            'code' => 'TH',
            'iso3' => 'THA',
            'capital' => 'Bangkok',
            'ndma' => 'Department of Disaster Prevention and Mitigation (DDPM)',
            'hotline' => '1784 / 199',
        ],
        'Timor-Leste' => [
            'code' => 'TL',
            'iso3' => 'TLS',
            'capital' => 'Dili',
            'ndma' => 'Direcção Nacional de Protecção Civil (DNPC)',
            'hotline' => '115 / 112',
        ],
        'Vietnam' => [
            'code' => 'VN',
            'iso3' => 'VNM',
            'capital' => 'Hanoi',
            'ndma' => 'Vietnam Disaster and Dyke Management Authority (VDDMA)',
            'hotline' => '112 / 114 / (024) 3733 5694',
        ],
    ];

    /**
     * Compute comprehensive disaster analytics from verified telemetry events and weather.
     *
     * @param array<int, array<string, mixed>> $events Assessed disaster events
     * @param array<int, array<string, mixed>> $weatherList Capital weather data
     * @return array<string, mixed>
     */
    public function getAnalytics(array $events, array $weatherList = []): array
    {
        $weatherByCountry = [];
        foreach ($weatherList as $w) {
            $c = $w['country'] ?? '';
            if ($c !== '') {
                $weatherByCountry[strtolower($c)] = $w;
            }
        }

        // 1. Overview counts
        $totalEvents = count($events);
        $criticalEvents = 0;
        $highEvents = 0;
        $moderateEvents = 0;
        $lowEvents = 0;

        foreach ($events as $e) {
            $level = $e['risk_assessment']['level'] ?? 'Low';
            if ($level === DisasterRiskAssessmentService::LEVEL_CRITICAL) {
                $criticalEvents++;
            } elseif ($level === DisasterRiskAssessmentService::LEVEL_HIGH) {
                $highEvents++;
            } elseif ($level === DisasterRiskAssessmentService::LEVEL_MODERATE) {
                $moderateEvents++;
            } else {
                $lowEvents++;
            }
        }

        // 2. Disaster Type Distribution
        $typeMap = [];
        foreach ($events as $e) {
            $cat = (string) ($e['category'] ?? 'other');
            if (!isset($typeMap[$cat])) {
                $typeMap[$cat] = [
                    'category' => $cat,
                    'label' => $this->getCategoryLabel($cat),
                    'count' => 0,
                    'critical_count' => 0,
                    'color' => $this->getCategoryColor($cat),
                ];
            }
            $typeMap[$cat]['count']++;
            if (($e['risk_assessment']['level'] ?? '') === DisasterRiskAssessmentService::LEVEL_CRITICAL) {
                $typeMap[$cat]['critical_count']++;
            }
        }

        usort($typeMap, fn ($a, $b) => $b['count'] <=> $a['count']);
        $typeDistribution = array_map(function ($item) use ($totalEvents) {
            $item['percentage'] = $totalEvents > 0 ? round(($item['count'] / $totalEvents) * 100, 1) : 0.0;
            return $item;
        }, array_values($typeMap));

        // 3. Disaster Frequency by Country
        $countryFreq = [];
        foreach (self::ASEAN_COUNTRIES as $countryName => $meta) {
            $countryFreq[$countryName] = [
                'country' => $countryName,
                'code' => $meta['code'],
                'iso3' => $meta['iso3'],
                'total_events' => 0,
                'critical_events' => 0,
                'high_events' => 0,
                'peak_risk_score' => 0,
                'categories' => [],
            ];
        }

        $unknownCountryEvents = 0;

        foreach ($events as $e) {
            $c = $e['country'] ?? null;
            $cat = (string) ($e['category'] ?? 'other');
            $score = (int) ($e['risk_assessment']['score'] ?? 0);
            $lvl = $e['risk_assessment']['level'] ?? 'Low';

            if ($c && isset($countryFreq[$c])) {
                $countryFreq[$c]['total_events']++;
                if ($lvl === DisasterRiskAssessmentService::LEVEL_CRITICAL) {
                    $countryFreq[$c]['critical_events']++;
                } elseif ($lvl === DisasterRiskAssessmentService::LEVEL_HIGH) {
                    $countryFreq[$c]['high_events']++;
                }
                if ($score > $countryFreq[$c]['peak_risk_score']) {
                    $countryFreq[$c]['peak_risk_score'] = $score;
                }
                $countryFreq[$c]['categories'][$cat] = ($countryFreq[$c]['categories'][$cat] ?? 0) + 1;
            } else {
                $unknownCountryEvents++;
            }
        }

        // Rank countries by total events DESC, then peak risk score DESC
        usort($countryFreq, function ($a, $b) {
            if ($b['total_events'] !== $a['total_events']) {
                return $b['total_events'] <=> $a['total_events'];
            }
            return $b['peak_risk_score'] <=> $a['peak_risk_score'];
        });

        // 4. Monthly & Yearly Trends
        $monthlyMap = [];
        $yearlyMap = [];

        foreach ($events as $e) {
            $timeStr = (string) ($e['event_time'] ?? $e['event_date'] ?? '');
            if ($timeStr === '') {
                continue;
            }

            try {
                $date = Carbon::parse($timeStr);
                $ym = $date->format('Y-m');
                $y = $date->format('Y');

                if (!isset($monthlyMap[$ym])) {
                    $monthlyMap[$ym] = [
                        'period' => $ym,
                        'month_name' => $date->format('M Y'),
                        'count' => 0,
                        'critical' => 0,
                    ];
                }
                $monthlyMap[$ym]['count']++;
                if (($e['risk_assessment']['level'] ?? '') === DisasterRiskAssessmentService::LEVEL_CRITICAL) {
                    $monthlyMap[$ym]['critical']++;
                }

                if (!isset($yearlyMap[$y])) {
                    $yearlyMap[$y] = [
                        'year' => $y,
                        'count' => 0,
                    ];
                }
                $yearlyMap[$y]['count']++;
            } catch (\Throwable) {
                // Ignore unparseable timestamps
            }
        }

        // Sort monthly chronologically ASC
        ksort($monthlyMap);
        ksort($yearlyMap);

        // 5. ASEAN Country Comparisons Matrix
        $comparisonMatrix = [];
        foreach (self::ASEAN_COUNTRIES as $countryName => $meta) {
            $w = $weatherByCountry[strtolower($countryName)] ?? null;
            $matched = null;
            foreach ($countryFreq as $cf) {
                if ($cf['country'] === $countryName) {
                    $matched = $cf;
                    break;
                }
            }

            // Determine predominant hazard category
            $dominantCat = 'None';
            $maxCatCount = 0;
            if ($matched && !empty($matched['categories'])) {
                foreach ($matched['categories'] as $cat => $cnt) {
                    if ($cnt > $maxCatCount) {
                        $maxCatCount = $cnt;
                        $dominantCat = $this->getCategoryLabel($cat);
                    }
                }
            }

            $peakScore = $matched['peak_risk_score'] ?? 0;
            $countryRiskTier = match (true) {
                $peakScore >= 80 => 'Critical',
                $peakScore >= 60 => 'High',
                $peakScore >= 35 => 'Moderate',
                $peakScore > 0 => 'Low',
                default => 'Nominal',
            };

            $weatherAlert = 'Normal';
            if ($w) {
                $precip = (float) ($w['precipitation_mm'] ?? 0.0);
                $wind = (float) ($w['wind_speed_kmh'] ?? 0.0);
                if ($wind >= 60.0 || $precip >= 35.0) {
                    $weatherAlert = 'Severe Warning';
                } elseif ($wind >= 35.0 || $precip >= 15.0) {
                    $weatherAlert = 'Advisory';
                }
            }

            $comparisonMatrix[] = [
                'country' => $countryName,
                'code' => $meta['code'],
                'iso3' => $meta['iso3'],
                'capital' => $meta['capital'],
                'total_events' => $matched['total_events'] ?? 0,
                'critical_events' => $matched['critical_events'] ?? 0,
                'high_events' => $matched['high_events'] ?? 0,
                'peak_risk_score' => $peakScore,
                'risk_tier' => $countryRiskTier,
                'dominant_hazard' => $dominantCat,
                'capital_weather' => [
                    'temperature_c' => $w['temperature_c'] ?? null,
                    'condition' => $w['condition'] ?? 'Unknown',
                    'wind_speed_kmh' => $w['wind_speed_kmh'] ?? null,
                    'precipitation_mm' => $w['precipitation_mm'] ?? 0.0,
                    'alert_level' => $weatherAlert,
                ],
                'ndma_agency' => $meta['ndma'],
                'hotline' => $meta['hotline'],
            ];
        }

        return [
            'overview' => [
                'total_events' => $totalEvents,
                'critical_events' => $criticalEvents,
                'high_events' => $highEvents,
                'moderate_events' => $moderateEvents,
                'low_events' => $lowEvents,
                'unknown_country_events' => $unknownCountryEvents,
            ],
            'type_distribution' => $typeDistribution,
            'country_frequency' => array_values($countryFreq),
            'monthly_trends' => array_values($monthlyMap),
            'yearly_trends' => array_values($yearlyMap),
            'asean_comparison_matrix' => $comparisonMatrix,
        ];
    }

    private function getCategoryLabel(string $category): string
    {
        return match (strtolower($category)) {
            'earthquake' => 'Earthquake',
            'cyclone' => 'Tropical Cyclone',
            'flood' => 'Flood',
            'wildfire' => 'Wildfire',
            'volcano' => 'Volcano',
            'storm' => 'Severe Storm',
            default => 'Other Incidents',
        };
    }

    private function getCategoryColor(string $category): string
    {
        return match (strtolower($category)) {
            'earthquake' => '#f97316',
            'cyclone' => '#06b6d4',
            'flood' => '#3b82f6',
            'wildfire' => '#ef4444',
            'volcano' => '#a855f7',
            'storm' => '#8b5cf6',
            default => '#64748b',
        };
    }
}
