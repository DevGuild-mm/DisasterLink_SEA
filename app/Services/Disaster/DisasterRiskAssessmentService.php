<?php

namespace App\Services\Disaster;

class DisasterRiskAssessmentService
{
    public const LEVEL_CRITICAL = 'Critical';
    public const LEVEL_HIGH = 'High';
    public const LEVEL_MODERATE = 'Moderate';
    public const LEVEL_LOW = 'Low';

    /**
     * High seismic risk nations in the ASEAN region.
     */
    private const SEISMIC_ZONES = ['Indonesia', 'Philippines', 'Myanmar'];

    /**
     * Flood-prone basin and delta countries.
     */
    private const DELTA_ZONES = ['Vietnam', 'Thailand', 'Cambodia', 'Myanmar', 'Laos'];

    /**
     * Archipelagic/coastal exposed nations.
     */
    private const COASTAL_ZONES = ['Philippines', 'Indonesia', 'Malaysia', 'Timor-Leste', 'Brunei', 'Singapore', 'Vietnam', 'Thailand', 'Myanmar', 'Cambodia'];

    /**
     * Assess risk for a single disaster event against real-time environmental context.
     *
     * @param array<string, mixed> $event
     * @param array<string, array<string, mixed>> $weatherByCountry Map of country name => weather array
     * @param array<string, int> $countryReportCounts Active report counts by country
     * @return array<string, mixed>
     */
    public function assessEvent(
        array $event,
        array $weatherByCountry = [],
        array $countryReportCounts = []
    ): array {
        $factors = [];

        // 1. Hazard Severity Factor (0 - 40 points)
        $severityResult = $this->calculateSeverityScore($event);
        $severityScore = $severityResult['score'];
        if (!empty($severityResult['reason'])) {
            $factors[] = $severityResult['reason'];
        }

        // 2. Environmental & Weather Risk Indicator (0 - 25 points)
        $envResult = $this->calculateEnvironmentalScore($event, $weatherByCountry);
        $envScore = $envResult['score'];
        if (!empty($envResult['reason'])) {
            $factors[] = $envResult['reason'];
        }

        // 3. Vulnerability & Geographic Factor (0 - 20 points)
        $vulnResult = $this->calculateVulnerabilityScore($event);
        $vulnScore = $vulnResult['score'];
        if (!empty($vulnResult['reason'])) {
            $factors[] = $vulnResult['reason'];
        }

        // 4. Corroboration & Observation Factor (0 - 15 points)
        $corrobResult = $this->calculateCorroborationScore($event, $countryReportCounts);
        $corrobScore = $corrobResult['score'];
        if (!empty($corrobResult['reason'])) {
            $factors[] = $corrobResult['reason'];
        }

        $totalScore = (int) min(100, max(1, round($severityScore + $envScore + $vulnScore + $corrobScore)));

        $level = match (true) {
            $totalScore >= 75 => self::LEVEL_CRITICAL,
            $totalScore >= 55 => self::LEVEL_HIGH,
            $totalScore >= 35 => self::LEVEL_MODERATE,
            default => self::LEVEL_LOW,
        };

        $badgeColor = match ($level) {
            self::LEVEL_CRITICAL => 'rose',
            self::LEVEL_HIGH => 'amber',
            self::LEVEL_MODERATE => 'yellow',
            default => 'emerald',
        };

        return [
            'score' => $totalScore,
            'level' => $level,
            'badge_color' => $badgeColor,
            'breakdown' => [
                'hazard_severity' => $severityScore,
                'environmental_risk' => $envScore,
                'vulnerability' => $vulnScore,
                'corroboration' => $corrobScore,
            ],
            'factors' => $factors,
            'is_critical' => $level === self::LEVEL_CRITICAL,
            'is_high' => in_array($level, [self::LEVEL_CRITICAL, self::LEVEL_HIGH], true),
            'disclaimer' => 'Risk score is an analytical assessment based on observable physical telemetry and geographic exposure. It does not constitute a predictive forecast.',
        ];
    }

    /**
     * Batch assess an array of disaster events.
     *
     * @param array<int, array<string, mixed>> $events
     * @param array<int, array<string, mixed>> $weatherList
     * @param array<string, int> $countryReportCounts
     * @return array<int, array<string, mixed>> Events with embedded 'risk_assessment'
     */
    public function assessEvents(
        array $events,
        array $weatherList = [],
        array $countryReportCounts = []
    ): array {
        $weatherByCountry = [];
        foreach ($weatherList as $w) {
            $c = $w['country'] ?? '';
            if ($c !== '') {
                $weatherByCountry[strtolower($c)] = $w;
            }
        }

        return array_map(function (array $event) use ($weatherByCountry, $countryReportCounts) {
            $event['risk_assessment'] = $this->assessEvent($event, $weatherByCountry, $countryReportCounts);
            return $event;
        }, $events);
    }

    /**
     * Calculate hazard severity score (0 to 40).
     *
     * @param array<string, mixed> $event
     * @return array{score: int, reason: string|null}
     */
    protected function calculateSeverityScore(array $event): array
    {
        $category = strtolower((string) ($event['category'] ?? 'other'));
        $sevLevel = (string) ($event['severity_level'] ?? '');
        $magnitude = isset($event['magnitude']) && is_numeric($event['magnitude']) ? (float) $event['magnitude'] : null;
        $depth = isset($event['depth_km']) && is_numeric($event['depth_km']) ? (float) $event['depth_km'] : null;

        $score = 10;
        $reason = null;

        if ($category === 'earthquake') {
            if ($magnitude !== null) {
                if ($magnitude >= 7.5) {
                    $score = 40;
                    $reason = "Severe major earthquake (M{$magnitude}) with catastrophic local shaking potential.";
                } elseif ($magnitude >= 7.0) {
                    $score = 36;
                    $reason = "Strong major earthquake (M{$magnitude}) with wide destructive capability.";
                } elseif ($magnitude >= 6.5) {
                    $score = 30;
                    $reason = "Strong earthquake (M{$magnitude}) capable of substantial damage in populated areas.";
                } elseif ($magnitude >= 6.0) {
                    $score = 25;
                    $reason = "Moderate-to-strong seismic tremor (M{$magnitude}) recorded by regional telemetry.";
                } elseif ($magnitude >= 5.0) {
                    $score = 18;
                    $reason = "Moderate earthquake (M{$magnitude}) with localized felt intensity.";
                } elseif ($magnitude >= 4.0) {
                    $score = 12;
                    $reason = "Light seismic event (M{$magnitude}) detected.";
                } else {
                    $score = 8;
                    $reason = "Minor tremor (M{$magnitude}) recorded.";
                }

                // Shallow focal depth (< 25km) amplifies surface energy
                if ($depth !== null && $depth > 0 && $depth <= 25) {
                    $score = min(40, $score + 4);
                    $reason .= " Shallow focal depth ({$depth} km) amplifies surface wave shaking.";
                }
            } elseif ($sevLevel === 'Red') {
                $score = 35;
                $reason = "GDACS Red alert issued for high-impact seismic event.";
            } elseif ($sevLevel === 'Orange') {
                $score = 25;
                $reason = "GDACS Orange alert issued for notable seismic event.";
            }
        } elseif (in_array($category, ['cyclone', 'storm'], true)) {
            if ($sevLevel === 'Red') {
                $score = 38;
                $reason = "GDACS Red Alert: Severe tropical cyclone with destructive winds and surge danger.";
            } elseif ($sevLevel === 'Orange') {
                $score = 28;
                $reason = "GDACS Orange Alert: Tropical cyclone / tropical storm system with gale-force winds.";
            } elseif ($sevLevel === 'Green') {
                $score = 16;
                $reason = "Monitored tropical depression / atmospheric disturbance.";
            } else {
                $score = 20;
                $reason = "Severe storm system active in maritime or overland trajectory.";
            }
        } elseif ($category === 'flood') {
            if ($sevLevel === 'Red') {
                $score = 38;
                $reason = "GDACS Red Alert: Widespread inundation affecting critical settlements.";
            } elseif ($sevLevel === 'Orange') {
                $score = 28;
                $reason = "GDACS Orange Alert: Moderate riverine or flash flooding.";
            } else {
                $score = 22;
                $reason = "Confirmed flood inundation or rapid water level rise reported.";
            }
        } elseif ($category === 'volcano') {
            if ($sevLevel === 'Red') {
                $score = 38;
                $reason = "Major explosive volcanic activity with pyroclastic or dense ash plume danger.";
            } elseif ($sevLevel === 'Orange') {
                $score = 26;
                $reason = "Elevated volcanic unrest or localized eruption notice.";
            } else {
                $score = 18;
                $reason = "Volcanic alert level active with exclusion zone enforcement.";
            }
        } elseif ($category === 'wildfire') {
            $score = ($sevLevel === 'Red' || $sevLevel === 'Orange') ? 28 : 18;
            $reason = "Active thermal anomaly and vegetation fire cluster detected.";
        } else {
            $score = 14;
            $reason = "Incident telemetry tracked under standard observation protocol.";
        }

        return ['score' => min(40, max(5, $score)), 'reason' => $reason];
    }

    /**
     * Calculate real-time environmental context score (0 to 25).
     *
     * @param array<string, mixed> $event
     * @param array<string, array<string, mixed>> $weatherByCountry
     * @return array{score: int, reason: string|null}
     */
    protected function calculateEnvironmentalScore(array $event, array $weatherByCountry): array
    {
        $country = strtolower((string) ($event['country'] ?? ''));
        $weather = $weatherByCountry[$country] ?? null;

        if (!$weather) {
            return ['score' => 6, 'reason' => 'Baseline environmental conditions assumed (local atmospheric telemetry pending).'];
        }

        $precip = (float) ($weather['precipitation_mm'] ?? 0.0);
        $precipSum = (float) ($weather['forecast_precipitation_sum_mm'] ?? 0.0);
        $windSpeed = (float) ($weather['wind_speed_kmh'] ?? 0.0);
        $category = strtolower((string) ($event['category'] ?? 'other'));

        $score = 4;
        $reasons = [];

        // Precipitation analysis
        $totalRain = max($precip, $precipSum);
        if ($totalRain >= 40.0) {
            $score += 12;
            $reasons[] = "Heavy rainfall telemetry ({$totalRain} mm) recorded in region";
        } elseif ($totalRain >= 15.0) {
            $score += 7;
            $reasons[] = "Moderate rainfall ({$totalRain} mm) present";
        } elseif ($totalRain >= 5.0) {
            $score += 3;
        }

        // Wind speed analysis
        if ($windSpeed >= 60.0) {
            $score += 9;
            $reasons[] = "High gale-force wind gusts ({$windSpeed} km/h) active";
        } elseif ($windSpeed >= 35.0) {
            $score += 5;
            $reasons[] = "Brisk winds ({$windSpeed} km/h) reported";
        }

        // Multi-hazard compounding: heavy rain + earthquake increases landslide/mudflow risk
        if ($category === 'earthquake' && $totalRain >= 15.0) {
            $score += 4;
            $reasons[] = "Co-occurring precipitation elevates soil liquefaction and secondary landslide risk";
        }

        $reasonStr = !empty($reasons) ? implode('; ', $reasons) . '.' : 'Atmospheric conditions within typical seasonal ranges.';

        return ['score' => min(25, max(3, $score)), 'reason' => $reasonStr];
    }

    /**
     * Calculate location vulnerability factor (0 to 20).
     *
     * @param array<string, mixed> $event
     * @return array{score: int, reason: string|null}
     */
    protected function calculateVulnerabilityScore(array $event): array
    {
        $country = (string) ($event['country'] ?? '');
        $category = strtolower((string) ($event['category'] ?? 'other'));
        $score = 5;
        $reasons = [];

        if (in_array($country, self::SEISMIC_ZONES, true) && in_array($category, ['earthquake', 'volcano'], true)) {
            $score += 10;
            $reasons[] = "Located in the seismically active Pacific Rim / Indo-Australian subduction belt ({$country})";
        }

        if (in_array($country, self::DELTA_ZONES, true) && in_array($category, ['flood', 'cyclone', 'storm'], true)) {
            $score += 9;
            $reasons[] = "Located in flood-sensitive river basin / low-elevation drainage zone ({$country})";
        }

        if (in_array($country, self::COASTAL_ZONES, true) && in_array($category, ['cyclone', 'storm'], true)) {
            $score += 5;
            $reasons[] = "Exposed to maritime storm surges and coastal inundation";
        }

        $reasonStr = !empty($reasons) ? implode('; ', $reasons) . '.' : "Standard regional geographic exposure index for {$country}.";

        return ['score' => min(20, max(4, $score)), 'reason' => $reasonStr];
    }

    /**
     * Calculate multi-source corroboration and citizen report factor (0 to 15).
     *
     * @param array<string, mixed> $event
     * @param array<string, int> $countryReportCounts
     * @return array{score: int, reason: string|null}
     */
    protected function calculateCorroborationScore(array $event, array $countryReportCounts): array
    {
        $score = 4;
        $reasons = [];

        $sources = $event['sources'] ?? [$event['source'] ?? 'Unknown'];
        if (count($sources) > 1) {
            $score += 6;
            $reasons[] = 'Cross-validated across multiple official observational feeds (' . implode(', ', $sources) . ')';
        }

        $country = strtolower((string) ($event['country'] ?? ''));
        $reportCount = $countryReportCounts[$country] ?? 0;
        if ($reportCount > 0) {
            $score += min(5, $reportCount * 2);
            $reasons[] = "Corroborated by {$reportCount} verified on-the-ground citizen observation report(s)";
        }

        if (!empty($event['is_community_report'])) {
            $score += 3;
            $reasons[] = 'Direct verified eyewitness report with photo/GPS ground evidence';
        }

        $reasonStr = !empty($reasons) ? implode('; ', $reasons) . '.' : 'Single-feed telemetry confirmation.';

        return ['score' => min(15, max(3, $score)), 'reason' => $reasonStr];
    }
}
