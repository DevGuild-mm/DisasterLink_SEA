<?php

namespace App\Http\Controllers;

use App\Services\Disaster\DisasterDashboardService;
use App\Services\Disaster\DisasterIntelligenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DisasterIntelligenceController extends Controller
{
    public function __construct(
        protected DisasterIntelligenceService $intelligenceService,
        protected DisasterDashboardService $dashboardService,
    ) {
    }

    /**
     * Display the Advanced Disaster Intelligence & Early Warning System page.
     */
    public function index(Request $request): Response
    {
        @set_time_limit(120);

        $thresholds = [
            'min_level' => $request->query('min_level', 'moderate'),
            'country' => $request->query('country', 'All'),
            'category' => $request->query('category', 'All'),
            'min_magnitude' => $request->query('min_magnitude'),
        ];

        $forceRefresh = $request->boolean('refresh');
        $intelligence = $this->intelligenceService->getIntelligenceData($thresholds, $forceRefresh);
        $dashboardData = $this->dashboardService->getDashboardData($forceRefresh);

        return Inertia::render('IntelligencePage', [
            'intelligence' => $intelligence,
            'initialDashboardData' => $dashboardData,
            'filters' => $thresholds,
        ]);
    }

    /**
     * API endpoint for intelligence telemetry, early warnings, and analytics.
     */
    public function api(Request $request): JsonResponse
    {
        @set_time_limit(120);

        $thresholds = [
            'min_level' => $request->query('min_level', 'moderate'),
            'country' => $request->query('country', 'All'),
            'category' => $request->query('category', 'All'),
            'min_magnitude' => $request->query('min_magnitude'),
        ];

        $forceRefresh = $request->boolean('refresh');
        $intelligence = $this->intelligenceService->getIntelligenceData($thresholds, $forceRefresh);

        return response()->json($intelligence);
    }
}
