<?php

namespace App\Http\Controllers;

use App\Models\Shelter;
use App\Services\Disaster\DisasterDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        $shelters = Shelter::all();

        return Inertia::render('MapPage', [
            'initialDashboardData' => $dashboardData,
            'initialShelters' => $shelters,
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
     * Display Individual Disaster Event Details Page.
     */
    public function eventDetail(string $id): Response
    {
        @set_time_limit(120);
        $event = $this->dashboardService->getEventById($id);
        $dashboardData = $this->dashboardService->getDashboardData();

        return Inertia::render('EventDetailPage', [
            'event' => $event,
            'eventId' => $id,
            'initialDashboardData' => $dashboardData,
        ]);
    }

    /**
     * Display ASEAN Country Monitoring Dashboard.
     */
    public function countries(Request $request, ?string $country = null): Response
    {
        @set_time_limit(120);
        $forceRefresh = $request->boolean('refresh');
        $countryData = $this->dashboardService->getCountryDashboardData($country, $forceRefresh);
        $dashboardData = $this->dashboardService->getDashboardData();

        return Inertia::render('CountryMonitoringPage', [
            'countryData' => $countryData,
            'selectedCountry' => $countryData['selected_country'] ?? 'All',
            'initialDashboardData' => $dashboardData,
        ]);
    }

    /**
     * API endpoint for country-specific disaster telemetry and analytics.
     */
    public function apiCountries(Request $request, ?string $country = null): JsonResponse
    {
        @set_time_limit(120);
        $forceRefresh = $request->boolean('refresh');
        $countryData = $this->dashboardService->getCountryDashboardData($country, $forceRefresh);

        return response()->json($countryData);
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
     * API endpoint for 60-second periodic background polling and manual refresh.
     */
    public function api(Request $request): JsonResponse
    {
        @set_time_limit(120);
        $forceRefresh = $request->boolean('refresh');
        $dashboardData = $this->dashboardService->getDashboardData($forceRefresh);

        return response()->json($dashboardData);
    }
}
