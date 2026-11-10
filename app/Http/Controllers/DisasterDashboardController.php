<?php

namespace App\Http\Controllers;

use App\Models\Shelter;
use App\Services\Disaster\DisasterDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

class DisasterDashboardController extends Controller
{
    public function __construct(
        protected DisasterDashboardService $dashboardService,
    ) {}

    /**
     * Display the DisasterLinkSEA Homepage with real-time initial data.
     */
    public function home(): Response
    {
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
        $dashboardData = $this->dashboardService->getDashboardData();
        $shelters = config('modules.coordination') ? Shelter::all() : [];

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
        $dashboardData = $this->dashboardService->getDashboardData();

        return Inertia::render('EventsPage', [
            'initialDashboardData' => $dashboardData,
        ]);
    }

    /**
     * Display Individual Disaster Event Details Page. An unknown event id is a 404.
     */
    public function eventDetail(string $id): Response
    {
        $event = $this->dashboardService->getEventById($id)
            ?? abort(404, 'Disaster event not found.');
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
        $countryData = $this->dashboardService->getCountryDashboardData($country);
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
        $countryData = $this->dashboardService->getCountryDashboardData($country);

        return response()->json($countryData);
    }

    /**
     * Display Regional Weather Monitoring Page.
     */
    public function weather(): Response
    {
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
        $dashboardData = $this->dashboardService->getDashboardData();

        return Inertia::render('FreshnessPage', [
            'initialDashboardData' => $dashboardData,
        ]);
    }

    /**
     * API endpoint for 60-second background polling and the public "Reload data" button.
     *
     * `?refresh=1` rebuilds the payload from MySQL (at most once per 10 s for everyone) and is limited to 6 per
     * minute per visitor. It never contacts GDACS, USGS or Open-Meteo.
     */
    public function api(Request $request): JsonResponse
    {
        $reload = $request->boolean('refresh');

        if ($reload && ! RateLimiter::attempt('dashboard-reload:'.$request->ip(), 6, fn (): bool => true, 60)) {
            return response()->json(
                ['message' => 'Too many reloads. Please wait a moment.'],
                429,
                ['Retry-After' => (string) RateLimiter::availableIn('dashboard-reload:'.$request->ip())],
            );
        }

        return response()->json($this->dashboardService->getDashboardData($reload));
    }
}
