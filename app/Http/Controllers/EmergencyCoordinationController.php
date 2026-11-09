<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRescueRequestRequest;
use App\Http\Requests\StoreVolunteerRequest;
use App\Models\RescueRequest;
use App\Models\Shelter;
use App\Models\Volunteer;
use App\Services\Disaster\DisasterDashboardService;
use App\Services\Disaster\EmergencyCoordinationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EmergencyCoordinationController extends Controller
{
    public function __construct(
        protected EmergencyCoordinationService $coordinationService,
        protected DisasterDashboardService $dashboardService,
    ) {
    }

    /**
     * Display the Emergency Resource & Evacuation Coordination dashboard.
     */
    public function index(Request $request): Response
    {
        @set_time_limit(120);

        $filters = [
            'country' => $request->query('country', 'All'),
            'shelter_type' => $request->query('shelter_type', 'all'),
            'resource_category' => $request->query('resource_category', 'all'),
        ];

        $forceRefresh = $request->boolean('refresh');
        $coordinationData = $this->coordinationService->getCoordinationData($filters, $forceRefresh);
        $dashboardData = $this->dashboardService->getDashboardData($forceRefresh);

        return Inertia::render('EmergencyCoordinationPage', [
            'coordination' => $coordinationData,
            'initialDashboardData' => $dashboardData,
            'filters' => $filters,
        ]);
    }

    /**
     * Public JSON API endpoint for emergency coordination, shelters, and relief inventory.
     */
    public function api(Request $request): JsonResponse
    {
        @set_time_limit(120);

        $filters = [
            'country' => $request->query('country', 'All'),
            'shelter_type' => $request->query('shelter_type', 'all'),
            'resource_category' => $request->query('resource_category', 'all'),
        ];

        $forceRefresh = $request->boolean('refresh');
        $coordinationData = $this->coordinationService->getCoordinationData($filters, $forceRefresh);

        return response()->json($coordinationData);
    }

    /**
     * API endpoint returning shelters and relief centers formatted for map layers.
     */
    public function apiShelters(Request $request): JsonResponse
    {
        $country = $request->query('country');
        $query = Shelter::query();
        if ($country && strtolower($country) !== 'all') {
            $query->where('country', $country);
        }

        $shelters = $query->get();
        $mapLocations = $this->coordinationService->formatShelterLocationsForMap($shelters);

        return response()->json([
            'total' => count($mapLocations),
            'locations' => $mapLocations,
        ]);
    }

    /**
     * Public volunteer registration workflow.
     */
    public function registerVolunteer(StoreVolunteerRequest $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validated();
        $userId = $request->user()?->id;

        $volunteer = Volunteer::updateOrCreate(
            ['email' => $validated['email']],
            array_merge($validated, [
                'user_id' => $userId,
                'availability_status' => $validated['availability_status'] ?? Volunteer::STATUS_AVAILABLE,
            ])
        );

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Volunteer registration recorded successfully. Emergency dispatch coordinators have been notified.',
                'volunteer' => $volunteer,
            ], 201);
        }

        return redirect()->back()->with('success', 'Thank you! Your volunteer availability has been registered in the regional disaster response pool.');
    }

    /**
     * Citizen emergency rescue assistance request submission.
     */
    public function storeRescueRequest(StoreRescueRequestRequest $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validated();
        $userId = $request->user()?->id;

        $rescueRequest = RescueRequest::create(array_merge($validated, [
            'user_id' => $userId,
            'status' => RescueRequest::STATUS_PENDING,
        ]));

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Rescue request received and prioritized in the regional dispatch queue.',
                'rescue_request' => $rescueRequest,
            ], 201);
        }

        return redirect()->back()->with('success', 'Emergency rescue request submitted. First responders and disaster dispatch teams have received your coordinates.');
    }
}
