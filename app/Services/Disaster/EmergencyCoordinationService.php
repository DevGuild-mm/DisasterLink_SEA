<?php

namespace App\Services\Disaster;

use App\Models\EmergencyResource;
use App\Models\EvacuationRoute;
use App\Models\RescueRequest;
use App\Models\ResourceDistribution;
use App\Models\Shelter;
use App\Models\Volunteer;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class EmergencyCoordinationService
{
    private const CACHE_KEY = 'emergency_coordination_summary';
    private const CACHE_TTL = 60; // 60 seconds

    /**
     * Get complete coordination telemetry payload.
     *
     * @param array<string, mixed> $filters
     * @param bool $forceRefresh
     * @return array<string, mixed>
     */
    public function getCoordinationData(array $filters = [], bool $forceRefresh = false): array
    {
        $countryFilter = isset($filters['country']) && $filters['country'] !== '' && strtolower((string) $filters['country']) !== 'all'
            ? (string) $filters['country']
            : null;

        $shelterTypeFilter = isset($filters['shelter_type']) && $filters['shelter_type'] !== '' && strtolower((string) $filters['shelter_type']) !== 'all'
            ? (string) $filters['shelter_type']
            : null;

        $resourceCategoryFilter = isset($filters['resource_category']) && $filters['resource_category'] !== '' && strtolower((string) $filters['resource_category']) !== 'all'
            ? (string) $filters['resource_category']
            : null;

        // Query Shelters
        $sheltersQuery = Shelter::with('resources')->orderBy('country')->orderBy('city');
        if ($countryFilter !== null) {
            $sheltersQuery->where('country', $countryFilter);
        }
        if ($shelterTypeFilter !== null) {
            $sheltersQuery->where('type', $shelterTypeFilter);
        }
        $shelters = $sheltersQuery->get();

        // Query Resources
        $resourcesQuery = EmergencyResource::with('shelter:id,name,country,city')->orderBy('category')->orderBy('item_name');
        if ($resourceCategoryFilter !== null) {
            $resourcesQuery->where('category', $resourceCategoryFilter);
        }
        if ($countryFilter !== null) {
            $resourcesQuery->whereHas('shelter', fn ($q) => $q->where('country', $countryFilter));
        }
        $resources = $resourcesQuery->get();

        // Query Evacuation Routes
        $routesQuery = EvacuationRoute::orderBy('country')->orderBy('title');
        if ($countryFilter !== null) {
            $routesQuery->where('country', $countryFilter);
        }
        $routes = $routesQuery->get();

        // Query Rescue Requests
        $requestsQuery = RescueRequest::with('assignedVolunteer:id,name,phone')
            ->orderByRaw("CASE urgency WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'moderate' THEN 3 ELSE 4 END")
            ->latest('created_at');
        if ($countryFilter !== null) {
            $requestsQuery->where('country', $countryFilter);
        }
        $rescueRequests = $requestsQuery->get();

        // Query Volunteers
        $volunteersQuery = Volunteer::withCount('rescueRequests')->orderBy('availability_status')->orderBy('name');
        if ($countryFilter !== null) {
            $volunteersQuery->where('country', $countryFilter);
        }
        $volunteers = $volunteersQuery->get();

        // Compute Aggregated KPI Summary
        $allShelters = Shelter::all();
        $totalCapacity = (int) $allShelters->sum('total_capacity');
        $currentOccupancy = (int) $allShelters->sum('current_occupancy');
        $availableCapacity = max(0, $totalCapacity - $currentOccupancy);
        $overallOccupancyRate = $totalCapacity > 0 ? round(($currentOccupancy / $totalCapacity) * 100, 1) : 0.0;

        $allResources = EmergencyResource::all();
        $criticalShortages = $allResources->filter(fn ($r) => in_array($r->status, [EmergencyResource::STATUS_CRITICAL, EmergencyResource::STATUS_DEPLETED], true))->count();
        $lowSupplyCount = $allResources->filter(fn ($r) => $r->status === EmergencyResource::STATUS_LOW)->count();

        $allRequests = RescueRequest::all();
        $pendingRequests = $allRequests->where('status', RescueRequest::STATUS_PENDING)->count();
        $criticalRequests = $allRequests->where('urgency', RescueRequest::URGENCY_CRITICAL)->whereIn('status', [RescueRequest::STATUS_PENDING, RescueRequest::STATUS_DISPATCHED])->count();

        $allVolunteers = Volunteer::all();
        $availableVolunteers = $allVolunteers->where('availability_status', Volunteer::STATUS_AVAILABLE)->count();

        return [
            'generated_at' => Carbon::now()->toIso8601String(),
            'summary' => [
                'total_shelters' => $allShelters->count(),
                'open_shelters' => $allShelters->where('status', Shelter::STATUS_OPEN)->count(),
                'total_capacity' => $totalCapacity,
                'current_occupancy' => $currentOccupancy,
                'available_capacity' => $availableCapacity,
                'overall_occupancy_rate' => $overallOccupancyRate,
                'critical_shortages_count' => $criticalShortages,
                'low_supplies_count' => $lowSupplyCount,
                'active_evacuation_routes' => EvacuationRoute::count(),
                'pending_rescue_requests' => $pendingRequests,
                'critical_rescue_requests' => $criticalRequests,
                'total_volunteers' => $allVolunteers->count(),
                'available_volunteers' => $availableVolunteers,
            ],
            'shelters' => $shelters,
            'map_locations' => $this->formatShelterLocationsForMap($shelters),
            'resources' => $resources,
            'routes' => $routes,
            'rescue_requests' => $rescueRequests,
            'volunteers' => $volunteers,
            'countries' => DisasterDashboardService::ASEAN_COUNTRIES,
            'categories' => [
                ['id' => 'water_food', 'label' => 'Food & Potable Water', 'icon' => 'utensils'],
                ['id' => 'medical', 'label' => 'Medical & Trauma Care', 'icon' => 'stethoscope'],
                ['id' => 'rescue_equipment', 'label' => 'Rescue & Heavy Equipment', 'icon' => 'life-buoy'],
                ['id' => 'necessities', 'label' => 'Bedding, Hygiene & Shelter Goods', 'icon' => 'package'],
            ],
        ];
    }

    /**
     * Format shelter objects into map markers with full metadata for Leaflet.
     *
     * @param Collection<int, Shelter> $shelters
     * @return array<int, array<string, mixed>>
     */
    public function formatShelterLocationsForMap(Collection $shelters): array
    {
        return $shelters->map(function (Shelter $s) {
            return [
                'id' => $s->id,
                'name' => $s->name,
                'type' => $s->type,
                'type_label' => match ($s->type) {
                    Shelter::TYPE_HOSPITAL => 'Emergency Hospital',
                    Shelter::TYPE_RELIEF_CENTER => 'Relief Distribution Hub',
                    Shelter::TYPE_EVACUATION_CENTER => 'Designated Evacuation Center',
                    default => 'Emergency Shelter',
                },
                'country' => $s->country,
                'city' => $s->city,
                'address' => $s->address,
                'latitude' => $s->latitude,
                'longitude' => $s->longitude,
                'coordinates' => [$s->latitude, $s->longitude],
                'status' => $s->status,
                'status_badge' => match ($s->status) {
                    Shelter::STATUS_OPEN => 'Open',
                    Shelter::STATUS_FULL => 'At Capacity',
                    Shelter::STATUS_EVACUATING => 'Evacuating',
                    default => 'Closed',
                },
                'total_capacity' => $s->total_capacity,
                'current_occupancy' => $s->current_occupancy,
                'available_capacity' => $s->available_capacity,
                'occupancy_rate' => $s->occupancy_rate,
                'contact_name' => $s->contact_name,
                'contact_phone' => $s->contact_phone,
                'contact_email' => $s->contact_email,
                'facilities' => $s->facilities ?? [],
                'notes' => $s->notes,
                'resource_count' => $s->resources?->count() ?? 0,
            ];
        })->toArray();
    }
}
