<?php

namespace App\Services\Disaster;

use Carbon\Carbon;

class EarlyWarningService
{
    /**
     * Standard precautionary action checklists for qualifying hazards.
     */
    public const ADVISORIES = [
        'earthquake' => [
            'immediate_action' => 'Drop, Cover, and Hold On under heavy furniture or against an interior wall.',
            'precautions' => [
                'Expect aftershocks; avoid damaged masonry, bridges, and overhead powerlines.',
                'If near coastal waters, immediately move to higher ground in case of secondary tsunami waves.',
                'Shut off household gas valves and main electrical breakers if structural damage is suspected.',
                'Tune into national disaster management radio channels for official building clearance orders.',
            ],
            'emergency_level' => 'SEISMIC EMERGENCY ALERT',
        ],
        'cyclone' => [
            'immediate_action' => 'Seek shelter inside a sturdy wind-resistant structure away from exterior windows.',
            'precautions' => [
                'Evacuate immediately if located in designated low-lying coastal surge zones.',
                'Anchor or store all loose exterior property, equipment, and water vessels.',
                'Prepare non-perishable provisions, potable water (3 liters/person/day), and communication powerbanks.',
                'Do not venture outside during the eye of the storm; winds will reverse abruptly.',
            ],
            'emergency_level' => 'CYCLONIC / TYPHOON WARNING',
        ],
        'storm' => [
            'immediate_action' => 'Remain indoors away from glass windows and lightning conductors.',
            'precautions' => [
                'Avoid flooded roadways, riverbeds, and urban drainage viaducts.',
                'Disconnect sensitive electronic equipment from power grids.',
                'Monitor local municipal flood warnings and rapid water-level sirens.',
            ],
            'emergency_level' => 'SEVERE WEATHER ADVISORY',
        ],
        'flood' => [
            'immediate_action' => 'Ascend to higher floors or designated community evacuation high-ground.',
            'precautions' => [
                'Turn Around, Don’t Drown: never walk, swim, or drive through flowing water (15cm sweeps a person).',
                'Keep vital identification, prescription medication, and emergency documents in waterproof packs.',
                'Disconnect electricity at the main service switch if water reaches lower sockets.',
                'Avoid contact with contaminated floodwaters to prevent waterborne disease vectors.',
            ],
            'emergency_level' => 'FLASH / RIVER INUNDATION WARNING',
        ],
        'volcano' => [
            'immediate_action' => 'Adhere strictly to official exclusion zone boundaries and shelter indoors.',
            'precautions' => [
                'Wear N95/particulate respirators or damp cloths to protect respiratory tracts from abrasive ash.',
                'Protect eyes with goggles; avoid contact lens usage during active tephra fallout.',
                'Clear accumulated volcanic ash from flat roofs to prevent structural collapse under moisture.',
                'Avoid low-lying river valleys downstream of volcanic cones due to dangerous mudflows (lahars).',
            ],
            'emergency_level' => 'VOLCANIC HAZARD NOTICE',
        ],
        'wildfire' => [
            'immediate_action' => 'Prepare for immediate evacuation if advised by emergency services.',
            'precautions' => [
                'Close all doors, windows, and exterior vents to minimize hot ember infiltration.',
                'Keep vehicle fueled and parked facing the evacuation exit route with emergency bags inside.',
                'Maintain situational awareness of local wind shifts and access road closures.',
            ],
            'emergency_level' => 'WILDFIRE RAPID THREAT ALERT',
        ],
        'other' => [
            'immediate_action' => 'Follow emergency directives issued by local disaster response authorities.',
            'precautions' => [
                'Maintain active communication with neighborhood emergency coordinators.',
                'Keep emergency supplies and battery-powered illumination equipment readily accessible.',
            ],
            'emergency_level' => 'CIVIL SAFETY ADVISORY',
        ],
    ];

    /**
     * Generate deduplicated early warning alerts from assessed disaster events and weather signals.
     *
     * @param array<int, array<string, mixed>> $assessedEvents
     * @param array<int, array<string, mixed>> $weatherList
     * @param array<string, mixed> $thresholds Configurable thresholds
     * @return array{
     *     total_alerts: int,
     *     critical_alerts: int,
     *     high_alerts: int,
     *     moderate_alerts: int,
     *     active_thresholds: array<string, mixed>,
     *     alerts: array<int, array<string, mixed>>
     * }
     */
    public function generateAlerts(
        array $assessedEvents,
        array $weatherList = [],
        array $thresholds = []
    ): array {
        $minLevel = strtolower((string) ($thresholds['min_level'] ?? 'moderate')); // all, moderate, high, critical
        $filterCountry = isset($thresholds['country']) && $thresholds['country'] !== '' && strtolower((string) $thresholds['country']) !== 'all'
            ? strtolower((string) $thresholds['country'])
            : null;
        $filterCategory = isset($thresholds['category']) && $thresholds['category'] !== '' && strtolower((string) $thresholds['category']) !== 'all'
            ? strtolower((string) $thresholds['category'])
            : null;
        $minMagnitude = isset($thresholds['min_magnitude']) && is_numeric($thresholds['min_magnitude'])
            ? (float) $thresholds['min_magnitude']
            : null;

        $alerts = [];
        $seenFingerprints = [];

        // 1. Process Assessed Disaster Events
        foreach ($assessedEvents as $event) {
            $assessment = $event['risk_assessment'] ?? null;
            $level = $assessment['level'] ?? 'Low';
            $score = $assessment['score'] ?? 0;
            $cat = strtolower((string) ($event['category'] ?? 'other'));
            $country = (string) ($event['country'] ?? 'Unknown');
            $countryLower = strtolower($country);
            $mag = isset($event['magnitude']) && is_numeric($event['magnitude']) ? (float) $event['magnitude'] : null;

            // Qualification Check:
            // Qualifies if score >= 35 (Moderate, High, or Critical) OR magnitude >= 5.5 OR GDACS Orange/Red
            $sevLevel = (string) ($event['severity_level'] ?? '');
            $isQualifying = $score >= 35
                || in_array($sevLevel, ['Red', 'Orange'], true)
                || ($mag !== null && $mag >= 5.5);

            if (!$isQualifying) {
                continue;
            }

            // Apply Filters:
            if ($filterCountry !== null && $countryLower !== $filterCountry) {
                continue;
            }

            if ($filterCategory !== null && $cat !== $filterCategory) {
                continue;
            }

            if ($minMagnitude !== null && ($mag === null || $mag < $minMagnitude)) {
                continue;
            }

            if ($minLevel === 'critical' && $level !== DisasterRiskAssessmentService::LEVEL_CRITICAL) {
                continue;
            }

            if ($minLevel === 'high' && !in_array($level, [DisasterRiskAssessmentService::LEVEL_CRITICAL, DisasterRiskAssessmentService::LEVEL_HIGH], true)) {
                continue;
            }

            if ($minLevel === 'moderate' && $level === DisasterRiskAssessmentService::LEVEL_LOW) {
                continue;
            }

            // Deduplication Fingerprint:
            $lat = isset($event['latitude']) && is_numeric($event['latitude']) ? round((float) $event['latitude'], 1) : 0.0;
            $lng = isset($event['longitude']) && is_numeric($event['longitude']) ? round((float) $event['longitude'], 1) : 0.0;
            $date = substr((string) ($event['event_date'] ?? $event['event_time'] ?? ''), 0, 10);
            $fingerprint = hash('sha256', "{$cat}_{$countryLower}_{$lat}_{$lng}_{$date}");

            if (isset($seenFingerprints[$fingerprint])) {
                continue;
            }
            $seenFingerprints[$fingerprint] = true;

            $advisoryKey = match ($cat) {
                'earthquake' => 'earthquake',
                'cyclone' => 'cyclone',
                'storm' => 'storm',
                'flood' => 'flood',
                'volcano' => 'volcano',
                'wildfire' => 'wildfire',
                default => 'other',
            };
            $advisory = self::ADVISORIES[$advisoryKey] ?? self::ADVISORIES['other'];

            $alerts[] = [
                'id' => 'alert_' . substr($fingerprint, 0, 12),
                'fingerprint' => $fingerprint,
                'event_id' => $event['id'] ?? null,
                'title' => $event['title'] ?? 'Hazard Alert',
                'category' => $cat,
                'raw_type' => $event['raw_type'] ?? $cat,
                'severity_level' => $level, // Critical, High, Moderate
                'risk_score' => $score,
                'badge_color' => $assessment['badge_color'] ?? 'amber',
                'country' => $country,
                'location' => $event['location'] ?? $country,
                'coordinates' => $event['coordinates'] ?? null,
                'latitude' => $event['latitude'] ?? null,
                'longitude' => $event['longitude'] ?? null,
                'issued_at' => $event['event_time'] ?? Carbon::now()->toIso8601String(),
                'updated_at' => $event['last_updated_time'] ?? $event['event_time'] ?? Carbon::now()->toIso8601String(),
                'primary_source' => $event['source'] ?? 'Regional Sensor Array',
                'sources' => $event['sources'] ?? [$event['source'] ?? 'Regional Sensor Array'],
                'source_url' => $event['source_url'] ?? null,
                'emergency_headline' => $advisory['emergency_level'],
                'immediate_action' => $advisory['immediate_action'],
                'protective_actions' => $advisory['precautions'],
                'factors' => $assessment['factors'] ?? [],
                'breakdown' => $assessment['breakdown'] ?? null,
                'is_community_report' => !empty($event['is_community_report']),
            ];
        }

        // 2. Process Severe Atmospheric Alerts from Weather Telemetry
        foreach ($weatherList as $w) {
            $country = (string) ($w['country'] ?? '');
            $countryLower = strtolower($country);
            $precip = (float) ($w['precipitation_mm'] ?? 0.0);
            $precipSum = (float) ($w['forecast_precipitation_sum_mm'] ?? 0.0);
            $windSpeed = (float) ($w['wind_speed_kmh'] ?? 0.0);
            $city = (string) ($w['city'] ?? $country);

            $maxRain = max($precip, $precipSum);
            $isSevereWind = $windSpeed >= 60.0;
            $isSevereRain = $maxRain >= 35.0;

            if (!$isSevereWind && !$isSevereRain) {
                continue;
            }

            if ($filterCountry !== null && $countryLower !== $filterCountry) {
                continue;
            }

            if ($filterCategory !== null && !in_array($filterCategory, ['storm', 'cyclone', 'flood'], true)) {
                continue;
            }

            $weatherSeverity = ($windSpeed >= 80.0 || $maxRain >= 60.0)
                ? DisasterRiskAssessmentService::LEVEL_CRITICAL
                : DisasterRiskAssessmentService::LEVEL_HIGH;

            if ($minLevel === 'critical' && $weatherSeverity !== DisasterRiskAssessmentService::LEVEL_CRITICAL) {
                continue;
            }

            $dateToday = Carbon::today()->toDateString();
            $fingerprint = hash('sha256', "weather_{$countryLower}_{$city}_{$dateToday}");

            if (isset($seenFingerprints[$fingerprint])) {
                continue;
            }
            $seenFingerprints[$fingerprint] = true;

            $weatherCat = $isSevereRain ? 'flood' : 'storm';
            $advisory = self::ADVISORIES[$weatherCat] ?? self::ADVISORIES['storm'];

            $factors = [];
            if ($isSevereRain) {
                $factors[] = "Severe localized precipitation telemetry: {$maxRain} mm recorded/forecast.";
            }
            if ($isSevereWind) {
                $factors[] = "High gale-force wind speed: {$windSpeed} km/h recorded in atmospheric model.";
            }

            $alerts[] = [
                'id' => 'alert_' . substr($fingerprint, 0, 12),
                'fingerprint' => $fingerprint,
                'event_id' => null,
                'title' => "Severe Weather Advisory for {$city}, {$country}",
                'category' => $weatherCat,
                'raw_type' => 'Atmospheric Telemetry Alert',
                'severity_level' => $weatherSeverity,
                'risk_score' => $weatherSeverity === DisasterRiskAssessmentService::LEVEL_CRITICAL ? 85 : 72,
                'badge_color' => $weatherSeverity === DisasterRiskAssessmentService::LEVEL_CRITICAL ? 'rose' : 'amber',
                'country' => $country,
                'location' => "{$city}, {$country}",
                'coordinates' => isset($w['latitude'], $w['longitude']) ? [(float) $w['latitude'], (float) $w['longitude']] : null,
                'latitude' => $w['latitude'] ?? null,
                'longitude' => $w['longitude'] ?? null,
                'issued_at' => $w['observation_time'] ?? Carbon::now()->toIso8601String(),
                'updated_at' => Carbon::now()->toIso8601String(),
                'primary_source' => 'Open-Meteo High-Resolution Telemetry',
                'sources' => ['Open-Meteo'],
                'source_url' => 'https://open-meteo.com/',
                'emergency_headline' => $advisory['emergency_level'],
                'immediate_action' => $advisory['immediate_action'],
                'protective_actions' => $advisory['precautions'],
                'factors' => $factors,
                'breakdown' => [
                    'hazard_severity' => 28,
                    'environmental_risk' => 24,
                    'vulnerability' => 12,
                    'corroboration' => 8,
                ],
                'is_community_report' => false,
            ];
        }

        // Sort Alerts: Critical first, then High, then Moderate; then newest
        usort($alerts, function (array $a, array $b) {
            $pMap = ['Critical' => 3, 'High' => 2, 'Moderate' => 1, 'Low' => 0];
            $pA = $pMap[$a['severity_level'] ?? ''] ?? 0;
            $pB = $pMap[$b['severity_level'] ?? ''] ?? 0;

            if ($pA !== $pB) {
                return $pB <=> $pA;
            }

            return ($b['risk_score'] ?? 0) <=> ($a['risk_score'] ?? 0);
        });

        $criticalCount = count(array_filter($alerts, fn ($a) => ($a['severity_level'] ?? '') === DisasterRiskAssessmentService::LEVEL_CRITICAL));
        $highCount = count(array_filter($alerts, fn ($a) => ($a['severity_level'] ?? '') === DisasterRiskAssessmentService::LEVEL_HIGH));
        $moderateCount = count(array_filter($alerts, fn ($a) => ($a['severity_level'] ?? '') === DisasterRiskAssessmentService::LEVEL_MODERATE));

        return [
            'total_alerts' => count($alerts),
            'critical_alerts' => $criticalCount,
            'high_alerts' => $highCount,
            'moderate_alerts' => $moderateCount,
            'active_thresholds' => [
                'min_level' => $minLevel,
                'country' => $thresholds['country'] ?? 'All',
                'category' => $thresholds['category'] ?? 'All',
                'min_magnitude' => $minMagnitude,
            ],
            'alerts' => $alerts,
        ];
    }
}
