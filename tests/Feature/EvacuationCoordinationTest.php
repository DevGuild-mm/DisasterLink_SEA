<?php

use App\Models\EvacuationRoute;
use App\Models\Shelter;
use App\Services\Disaster\EmergencyCoordinationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->coordinationService = new EmergencyCoordinationService();
});

test('retrieves verified evacuation routes with hazard status and waypoints', function () {
    $route = EvacuationRoute::create([
        'title' => 'Da Nang Coastal to High Ground Sports Complex',
        'country' => 'Vietnam',
        'origin' => 'Son Tra Beach Road',
        'destination' => 'Tien Son Stadium Hub',
        'distance_km' => 6.5,
        'status' => EvacuationRoute::STATUS_CAUTION,
        'hazard_warning' => 'Coastal gale winds exceeding 65 km/h; use Tran Thi Ly Bridge.',
        'instructions' => "1. Avoid open coastal road.\n2. Proceed inland via Tran Thi Ly Bridge.\n3. Follow civil defense reception marshals.",
        'waypoints' => [
            ['name' => 'Son Tra Assembly Hub', 'lat' => 16.08, 'lng' => 108.24, 'note' => 'Buses stationed'],
            ['name' => 'Bridge High Deck', 'lat' => 16.05, 'lng' => 108.22, 'note' => 'Clear passage'],
        ],
        'is_verified' => true,
        'verified_by' => 'VNDMA Region IV',
    ]);

    $data = $this->coordinationService->getCoordinationData(['country' => 'Vietnam']);

    expect($data['routes'])->toHaveCount(1)
        ->and($data['routes'][0]['title'])->toBe('Da Nang Coastal to High Ground Sports Complex')
        ->and($data['routes'][0]['status'])->toBe('caution')
        ->and($data['routes'][0]['is_verified'])->toBeTrue()
        ->and($data['routes'][0]['waypoints'])->toHaveCount(2);
});

test('formats shelters as map locations with complete coordinates and occupancy info', function () {
    $shelter = Shelter::create([
        'name' => 'Naypyidaw Central Indoor Shelter',
        'type' => Shelter::TYPE_EVACUATION_CENTER,
        'country' => 'Myanmar',
        'city' => 'Naypyidaw',
        'address' => 'Zabuthiri',
        'latitude' => 19.74,
        'longitude' => 96.08,
        'contact_name' => 'U Myint',
        'contact_phone' => '+95 67 111 222',
        'total_capacity' => 1000,
        'current_occupancy' => 250,
        'status' => Shelter::STATUS_OPEN,
    ]);

    $mapLocations = $this->coordinationService->formatShelterLocationsForMap(collect([$shelter]));

    expect($mapLocations)->toHaveCount(1)
        ->and($mapLocations[0]['id'])->toBe($shelter->id)
        ->and($mapLocations[0]['coordinates'])->toBe([19.74, 96.08])
        ->and($mapLocations[0]['type_label'])->toBe('Designated Evacuation Center')
        ->and($mapLocations[0]['available_capacity'])->toBe(750)
        ->and($mapLocations[0]['occupancy_rate'])->toBe(25.0);
});
