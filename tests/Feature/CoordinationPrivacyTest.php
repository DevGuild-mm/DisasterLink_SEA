<?php

use App\Models\RescueRequest;
use App\Models\Shelter;
use App\Models\Volunteer;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    config(['modules.coordination' => true]);
    Cache::flush();
});

test('public coordination payloads never expose volunteer or rescue requester personal data', function () {
    Volunteer::create([
        'name' => 'Sgt. Alex Cruz',
        'email' => 'alex.cruz@rescue.test',
        'phone' => '+63 919 888 7777',
        'country' => 'Philippines',
        'city' => 'Manila',
        'availability_status' => Volunteer::STATUS_AVAILABLE,
    ]);
    RescueRequest::create([
        'reporter_name' => 'Maria Santos',
        'contact_phone' => '+63 920 111 2222',
        'country' => 'Philippines',
        'location' => 'Flooded Compound, Pasig City',
        'people_count' => 4,
        'urgency' => RescueRequest::URGENCY_HIGH,
        'status' => RescueRequest::STATUS_PENDING,
        'situation_description' => 'Elderly resident needs evacuation.',
    ]);
    Shelter::create([
        'name' => 'Manila Hall',
        'type' => Shelter::TYPE_EVACUATION_CENTER,
        'country' => 'Philippines',
        'city' => 'Manila',
        'address' => 'Rizal Ave',
        'contact_name' => 'Hall Manager',
        'contact_phone' => '+63 2 000 0000',
        'latitude' => 14.6,
        'longitude' => 120.98,
        'total_capacity' => 100,
        'current_occupancy' => 10,
        'status' => Shelter::STATUS_OPEN,
    ]);

    $json = $this->getJson('/api/coordination')->assertOk()->getContent();

    foreach (['alex.cruz@rescue.test', '+63 919 888 7777', 'Sgt. Alex Cruz', 'Maria Santos', '+63 920 111 2222', 'Pasig City'] as $secret) {
        expect($json)->not->toContain($secret);
    }

    $this->getJson('/api/coordination')
        ->assertJsonMissingPath('rescue_requests')
        ->assertJsonMissingPath('volunteers')
        ->assertJsonPath('summary.total_volunteers', 1)
        ->assertJsonPath('summary.pending_rescue_requests', 1);
});

test('volunteer and rescue request models hide contact fields when serialized', function () {
    $volunteer = Volunteer::create([
        'name' => 'Sgt. Alex Cruz',
        'email' => 'alex.cruz@rescue.test',
        'phone' => '+63 919 888 7777',
        'country' => 'Philippines',
        'city' => 'Manila',
        'availability_status' => Volunteer::STATUS_AVAILABLE,
    ]);
    $rescue = RescueRequest::create([
        'reporter_name' => 'Maria Santos',
        'contact_phone' => '+63 920 111 2222',
        'country' => 'Philippines',
        'location' => 'Pasig',
        'people_count' => 2,
        'urgency' => RescueRequest::URGENCY_LOW,
        'status' => RescueRequest::STATUS_PENDING,
        'situation_description' => 'Test',
    ]);

    expect($volunteer->toArray())->not->toHaveKeys(['email', 'phone'])
        ->and($rescue->toArray())->not->toHaveKeys(['reporter_name', 'contact_phone']);
});
