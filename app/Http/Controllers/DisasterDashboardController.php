<?php

namespace App\Http\Controllers;

use App\Models\Country;
use App\Models\EmergencyContact;
use App\Models\SafetyGuide;
use App\Models\Shelter;
use App\Services\Disaster\Dashboard\CountryAlertsQuery;
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
    public function home(Request $request, CountryAlertsQuery $countryAlerts): Response
    {
        $dashboardData = $this->dashboardService->getDashboardData();

        $countries = Country::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'iso2', 'name']);
        $country = $countries->firstWhere('id', $request->user()?->country_id);

        // HLD §8.1: up to 3 published guides (global, or the signed-in user's country) and up to 3 contacts.
        $guides = SafetyGuide::query()
            ->published()
            ->where(fn ($q) => $q->whereNull('country_id')->when($country, fn ($w) => $w->orWhere('country_id', $country->id)))
            ->orderBy('sort_order')->orderBy('title')
            ->limit(3)
            ->get(['id', 'title', 'summary', 'hazard_type']);

        $contacts = $country === null ? collect() : EmergencyContact::query()
            ->published()
            ->where('country_id', $country->id)
            ->orderBy('sort_order')->orderBy('agency_name')
            ->limit(3)
            ->get(['id', 'agency_name', 'category', 'phone'])
            ->map(fn (EmergencyContact $c): array => [
                'id' => $c->id,
                'agency_name' => $c->agency_name,
                'category' => $c->category,
                'phone' => $c->phone,
                'phone_link' => EmergencyContact::telLink($c->phone),
            ]);

        return Inertia::render('Welcome', [
            'initialDashboardData' => $dashboardData,
            'status' => $request->session()->get('status'),
            'countryAlerts' => $request->user() === null ? null : [
                'country' => $country,
                'alerts' => $country === null ? [] : $countryAlerts->forCountry($country->id),
                'window_days' => CountryAlertsQuery::WINDOW_DAYS,
            ],
            'guidesPreview' => $guides,
            'contactsPreview' => $contacts,
            'contactsCountry' => $country,
            'countries' => $countries,
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
