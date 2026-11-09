<?php

namespace App\Services\Disaster;

use App\Models\DisasterReport;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ResponseIntelligenceService
{
    public const PRIORITY_IMMEDIATE = 'Immediate / Critical';
    public const PRIORITY_HIGH = 'High Urgency';
    public const PRIORITY_ELEVATED = 'Elevated Priority';
    public const PRIORITY_ROUTINE = 'Routine Monitoring';

    /**
     * Prioritize and triage citizen disaster reports against active early warning zones.
     *
     * @param Collection<int, DisasterReport>|array<int, DisasterReport> $reports
     * @param array<int, array<string, mixed>> $activeAlerts
     * @return array{
     *     total_reports: int,
     *     immediate_count: int,
     *     high_count: int,
     *     elevated_count: int,
     *     routine_count: int,
     *     reports: array<int, array<string, mixed>>
     * }
     */
    public function triageReports($reports, array $activeAlerts = []): array
    {
        $triaged = [];
        $immediateCount = 0;
        $highCount = 0;
        $elevatedCount = 0;
        $routineCount = 0;

        foreach ($reports as $report) {
            $reportData = $this->evaluateSingleReport($report, $activeAlerts);
            $triaged[] = $reportData;

            $tier = $reportData['priority_tier'];
            if ($tier === self::PRIORITY_IMMEDIATE) {
                $immediateCount++;
            } elseif ($tier === self::PRIORITY_HIGH) {
                $highCount++;
            } elseif ($tier === self::PRIORITY_ELEVATED) {
                $elevatedCount++;
            } else {
                $routineCount++;
            }
        }

        // Sort by triage score DESC, then newest incident
        usort($triaged, function (array $a, array $b) {
            if ($b['triage_score'] !== $a['triage_score']) {
                return $b['triage_score'] <=> $a['triage_score'];
            }
            return strcmp($b['incident_time'] ?? '', $a['incident_time'] ?? '');
        });

        return [
            'total_reports' => count($triaged),
            'immediate_count' => $immediateCount,
            'high_count' => $highCount,
            'elevated_count' => $elevatedCount,
            'routine_count' => $routineCount,
            'reports' => $triaged,
        ];
    }

    /**
     * Evaluate triage score and response priority for a single report.
     *
     * @param DisasterReport $report
     * @param array<int, array<string, mixed>> $activeAlerts
     * @return array<string, mixed>
     */
    public function evaluateSingleReport(DisasterReport $report, array $activeAlerts = []): array
    {
        $score = 20; // baseline score
        $reasons = [];

        // 1. Hazard Type Severity (0 - 30 pts)
        $type = strtolower($report->disaster_type);
        if (in_array($type, ['earthquake', 'tsunami', 'volcanic activity'], true)) {
            $score += 28;
            $reasons[] = 'Rapid-onset geophysical hazard with catastrophic structural hazard potential';
        } elseif (in_array($type, ['flood', 'landslide'], true)) {
            $score += 24;
            $reasons[] = 'Severe hydrometeorological inundation / mass slope displacement';
        } elseif (in_array($type, ['storm', 'typhoon', 'cyclone', 'wildfire'], true)) {
            $score += 20;
            $reasons[] = 'Severe atmospheric / thermal hazard report';
        } else {
            $score += 10;
            $reasons[] = 'Localized incident report';
        }

        // 2. Verified Evidence Indicators (0 - 25 pts)
        $hasCoords = $report->latitude !== null && $report->longitude !== null;
        if ($hasCoords) {
            $score += 10;
            $reasons[] = 'Calibrated GPS geo-coordinates provided';
        }

        $photoCount = is_array($report->photos) ? count($report->photos) : 0;
        if ($photoCount > 0) {
            $score += min(15, $photoCount * 8);
            $reasons[] = "{$photoCount} photographic ground evidence attachment(s) verified";
        }

        // 3. Proximity to Active Early Warning Zones (0 - 25 pts)
        $nearbyAlert = null;
        $minDistanceKm = null;

        if ($hasCoords && !empty($activeAlerts)) {
            foreach ($activeAlerts as $alert) {
                if (!isset($alert['latitude'], $alert['longitude']) || $alert['latitude'] === null || $alert['longitude'] === null) {
                    continue;
                }
                $dist = $this->calculateHaversineDistance(
                    (float) $report->latitude,
                    (float) $report->longitude,
                    (float) $alert['latitude'],
                    (float) $alert['longitude']
                );

                if ($minDistanceKm === null || $dist < $minDistanceKm) {
                    $minDistanceKm = round($dist, 1);
                    $nearbyAlert = $alert;
                }
            }

            if ($minDistanceKm !== null && $minDistanceKm <= 100.0) {
                $score += 25;
                $reasons[] = "Proximity: Situated within {$minDistanceKm} km of active Early Warning ({$nearbyAlert['title']})";
            } elseif ($minDistanceKm !== null && $minDistanceKm <= 250.0) {
                $score += 14;
                $reasons[] = "Located within {$minDistanceKm} km corridor of regional hazard alert";
            }
        } elseif (!$hasCoords) {
            // Country-level correlation if coordinates absent
            foreach ($activeAlerts as $alert) {
                if (strcasecmp($alert['country'] ?? '', $report->country) === 0) {
                    $score += 12;
                    $reasons[] = "Co-occurs within country ({$report->country}) experiencing active warning signals";
                    break;
                }
            }
        }

        // 4. Temporal Decay / Urgency (0 - 20 pts)
        try {
            $incidentTime = $report->incident_time ? Carbon::parse($report->incident_time) : $report->created_at;
            $hoursOld = max(0, Carbon::now()->diffInHours($incidentTime));

            if ($hoursOld <= 3) {
                $score += 20;
                $reasons[] = 'Immediate freshness: Reported within the last 3 hours';
            } elseif ($hoursOld <= 12) {
                $score += 14;
                $reasons[] = 'Recent occurrence: Reported within the last 12 hours';
            } elseif ($hoursOld <= 24) {
                $score += 8;
                $reasons[] = 'Reported within past 24 hours';
            } else {
                $score += 2;
            }
        } catch (\Throwable) {
            $score += 5;
        }

        $finalScore = (int) min(100, max(5, $score));

        $tier = match (true) {
            $finalScore >= 75 => self::PRIORITY_IMMEDIATE,
            $finalScore >= 55 => self::PRIORITY_HIGH,
            $finalScore >= 35 => self::PRIORITY_ELEVATED,
            default => self::PRIORITY_ROUTINE,
        };

        $badgeColor = match ($tier) {
            self::PRIORITY_IMMEDIATE => 'rose',
            self::PRIORITY_HIGH => 'amber',
            self::PRIORITY_ELEVATED => 'yellow',
            default => 'slate',
        };

        // Recommended Response Action
        $recommendedAction = match ($tier) {
            self::PRIORITY_IMMEDIATE => 'Urgent field responder dispatch and civil protection notification required.',
            self::PRIORITY_HIGH => 'Prioritize administrative corroboration and monitor municipal dispatch feeds.',
            self::PRIORITY_ELEVATED => 'Standard verification pipeline; aggregate with nearby citizen observation clusters.',
            default => 'Routine monitoring status; queue for standard periodic review.',
        };

        return [
            'id' => $report->id,
            'title' => $report->title,
            'disaster_type' => $report->disaster_type,
            'category' => $report->category,
            'country' => $report->country,
            'location' => $report->location,
            'latitude' => $report->latitude,
            'longitude' => $report->longitude,
            'has_coordinates' => $hasCoords,
            'incident_time' => $report->incident_time?->toIso8601String(),
            'created_at' => $report->created_at?->toIso8601String(),
            'status' => $report->status,
            'photo_count' => $photoCount,
            'photo_urls' => $report->photo_urls,
            'submitter_name' => $report->user?->name ?? 'Community Member',
            'review_notes' => $report->review_notes,
            'rejection_reason' => $report->rejection_reason,
            'triage_score' => $finalScore,
            'priority_tier' => $tier,
            'badge_color' => $badgeColor,
            'triage_reasons' => $reasons,
            'recommended_action' => $recommendedAction,
            'nearest_alert_distance_km' => $minDistanceKm,
        ];
    }

    /**
     * Haversine formula to compute distance in km between two coordinate pairs.
     */
    private function calculateHaversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }
}
