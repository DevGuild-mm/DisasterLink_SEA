<?php

use App\Models\Shelter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['modules.coordination' => true]);
    Shelter::create([
        'name' => 'Bangkok Emergency Triage Facility',
        'type' => Shelter::TYPE_EVACUATION_CENTER,
        'country' => 'Thailand',
        'city' => 'Bangkok',
        'address' => 'Phahonyothin Rd',
        'latitude' => 13.82,
        'longitude' => 100.56,
        'contact_name' => 'Captain Somchai',
        'contact_phone' => '+66 2 999 8888',
        'total_capacity' => 1200,
        'current_occupancy' => 400,
        'status' => Shelter::STATUS_OPEN,
    ]);
});

test('GET /coordination renders Inertia EmergencyCoordinationPage with complete payload', function () {
    $response = $this->get('/coordination');

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('EmergencyCoordinationPage')
            ->has('coordination')
            ->has('coordination.summary')
            ->has('coordination.shelters')
            ->has('coordination.resources')
            ->has('coordination.routes')
            ->missing('coordination.rescue_requests')
            ->missing('coordination.volunteers')
            ->has('initialDashboardData')
            ->has('filters')
        );
});

test('GET /api/coordination returns complete JSON coordination payload', function () {
    $response = $this->getJson('/api/coordination?country=Thailand');

    $response->assertOk()
        ->assertJsonStructure([
            'generated_at',
            'summary' => [
                'total_shelters',
                'open_shelters',
                'total_capacity',
                'current_occupancy',
                'available_capacity',
                'critical_shortages_count',
                'pending_rescue_requests',
                'available_volunteers',
            ],
            'shelters',
            'map_locations',
            'resources',
            'routes',
            'countries',
        ]);
});

test('GET /api/shelters returns formatted map locations', function () {
    $response = $this->getJson('/api/shelters');

    $response->assertOk()
        ->assertJsonStructure([
            'total',
            'locations' => [
                '*' => [
                    'id',
                    'name',
                    'type',
                    'country',
                    'city',
                    'coordinates',
                    'total_capacity',
                    'current_occupancy',
                    'available_capacity',
                ],
            ],
        ]);
});
