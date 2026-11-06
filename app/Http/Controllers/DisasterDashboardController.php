<?php

namespace App\Http\Controllers;

use App\Services\Disaster\DisasterDashboardService;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

class DisasterDashboardController extends Controller
{
    public function __construct(
        protected DisasterDashboardService $dashboardService,
    ) {
    }

    /**
     * Display the DisasterLinkSEA Homepage with real-time initial data.
     */
    public function home(): Response
    {
        @set_time_limit(120);
        $dashboardData = $this->dashboardService->getDashboardData();

        return Inertia::render('Welcome', [
            'initialDashboardData' => $dashboardData,
        ]);
    }

    /**
     * Display Regional Overview Page.
     */
    public function overview(): Response
    {
        @set_time_limit(120);
        $dashboardData = $this->dashboardService->getDashboardData();

        return Inertia::render('Overview', [
            'initialDashboardData' => $dashboardData,
        ]);
    }

    /**
     * Display Interactive Disaster Map Page.
     */
    public function map(): Response
    {
        @set_time_limit(120);
        $dashboardData = $this->dashboardService->getDashboardData();

        return Inertia::render('MapPage', [
            'initialDashboardData' => $dashboardData,
        ]);
    }

    /**
     * Display Live Disaster Events Stream Page.
     */
    public function events(): Response
    {
        @set_time_limit(120);
        $dashboardData = $this->dashboardService->getDashboardData();

        return Inertia::render('EventsPage', [
            'initialDashboardData' => $dashboardData,
        ]);
    }

    /**
     * Display Regional Weather Monitoring Page.
     */
    public function weather(): Response
    {
        @set_time_limit(120);
        $dashboardData = $this->dashboardService->getDashboardData();

        return Inertia::render('WeatherPage', [
            'initialDashboardData' => $dashboardData,
        ]);
    }

    /**
     * Display Data Source Provenance & Freshness Diagnostics Page.
     */
    public function freshness(): Response
    {
        @set_time_limit(120);
        $dashboardData = $this->dashboardService->getDashboardData();

        return Inertia::render('FreshnessPage', [
            'initialDashboardData' => $dashboardData,
        ]);
    }

    /**
     * API endpoint for 60-second periodic background polling.
     */
    public function api(): JsonResponse
    {
        @set_time_limit(120);
        $dashboardData = $this->dashboardService->getDashboardData();

        return response()->json($dashboardData);
    }
}
