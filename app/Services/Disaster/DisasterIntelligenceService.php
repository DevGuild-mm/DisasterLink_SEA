<?php

namespace App\Services\Disaster;

use App\Models\DisasterReport;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

class DisasterIntelligenceService
{
    private const CACHE_KEY_PREFIX = 'disaster_intelligence_v1_';
    private const CACHE_TTL_SECONDS = 300; // 5 minutes cache

    public function __construct(
        protected DisasterDashboardService $dashboardService,
        protected DisasterRiskAssessmentService $riskAssessmentService,
        protected EarlyWarningService $earlyWarningService,
        protected DisasterAnalyticsService $analyticsService,
        protected ResponseIntelligenceService $responseIntelligenceService,
    ) {
    }

    /**
     * Get aggregated Intelligence, Early Warnings, Risk Assessments, Analytics, and Triage.
     *
     * @param array<string, mixed> $thresholds User-configurable alert thresholds
     * @param bool $forceRefresh Force bypassing cached payload
     * @return array<string, mixed>
     */
    public function getIntelligenceData(array $thresholds = [], bool $forceRefresh = false): array
    {
        $minLevel = (string) ($thresholds['min_level'] ?? 'moderate');
        $country = (string) ($thresholds['country'] ?? 'All');
        $category = (string) ($thresholds['category'] ?? 'All');
        $minMag = (string) ($thresholds['min_magnitude'] ?? '');

        $cacheSubKey = md5("{$minLevel}_{$country}_{$category}_{$minMag}");
        $cacheKey = self::CACHE_KEY_PREFIX . $cacheSubKey;

        if (!$forceRefresh && Cache::has($cacheKey)) {
            /** @var array<string, mixed> $cached */
            $cached = Cache::get($cacheKey);
            $cached['cached'] = true;
            return $cached;
        }

        // 1. Fetch live or cached dashboard data (events, weather, sources)
        $dashboardData = $this->dashboardService->getDashboardData($forceRefresh);
        $rawEvents = $dashboardData['events'] ?? [];
        $weatherList = $dashboardData['weather'] ?? [];

        // 2. Fetch recent community reports for triage and corroboration
        try {
            $recentReports = DisasterReport::with(['user:id,name', 'reviewer:id,name'])
                ->latest('created_at')
                ->take(50)
                ->get();
        } catch (Throwable) {
            $recentReports = collect();
        }

        // Compute country report counts for corroboration
        $countryReportCounts = [];
        foreach ($recentReports as $rep) {
            $c = strtolower($rep->country);
            $countryReportCounts[$c] = ($countryReportCounts[$c] ?? 0) + 1;
        }

        // 3. Risk Assessment Engine: Assess all disaster events
        $assessedEvents = $this->riskAssessmentService->assessEvents(
            $rawEvents,
            $weatherList,
            $countryReportCounts
        );

        // 4. Early Warning Alert Management System: Detect qualifying events and deduplicate
        $earlyWarnings = $this->earlyWarningService->generateAlerts(
            $assessedEvents,
            $weatherList,
            $thresholds
        );

        // 5. Disaster Analytics Engine: Trends, distributions, country frequency, ASEAN matrix
        $analytics = $this->analyticsService->getAnalytics(
            $assessedEvents,
            $weatherList
        );

        // 6. Response Intelligence: Prioritize incoming citizen reports
        $responseIntelligence = $this->responseIntelligenceService->triageReports(
            $recentReports,
            $earlyWarnings['alerts'] ?? []
        );

        $payload = [
            'generated_at' => Carbon::now()->toIso8601String(),
            'cached' => false,
            'summary' => [
                'total_events' => count($assessedEvents),
                'active_alerts' => $earlyWarnings['total_alerts'] ?? 0,
                'critical_alerts' => $earlyWarnings['critical_alerts'] ?? 0,
                'high_alerts' => $earlyWarnings['high_alerts'] ?? 0,
                'triaged_reports' => $responseIntelligence['total_reports'] ?? 0,
                'immediate_response_needed' => $responseIntelligence['immediate_count'] ?? 0,
            ],
            'early_warnings' => $earlyWarnings,
            'assessed_events' => array_slice($assessedEvents, 0, 40),
            'analytics' => $analytics,
            'response_intelligence' => $responseIntelligence,
            'countries' => DisasterDashboardService::ASEAN_COUNTRIES,
            'categories' => $dashboardData['categories'] ?? [],
        ];

        Cache::put($cacheKey, $payload, self::CACHE_TTL_SECONDS);

        return $payload;
    }
}
