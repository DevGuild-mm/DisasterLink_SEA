<?php

use App\Models\RescueRequest;
use App\Models\User;
use App\Models\Volunteer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->user = User::factory()->create(['role' => 'user']);
});

test('public user can register as an emergency volunteer', function () {
    $payload = [
        'name' => 'Dr. Lisa Chen',
        'email' => 'lisachen.medic@gmail.com',
        'phone' => '+65 9123 4567',
        'country' => 'Singapore',
        'city' => 'Singapore',
        'skills' => ['First Aid / EMT', 'Medical Trauma Care'],
        'notes' => 'Available for cross-border deployment via AHA Centre.',
    ];

    $response = $this->postJson('/volunteers/register', $payload);

    $response->assertCreated()
        ->assertJson(['success' => true]);

    $this->assertDatabaseHas('volunteers', [
        'email' => 'lisachen.medic@gmail.com',
        'availability_status' => Volunteer::STATUS_AVAILABLE,
    ]);
});

test('citizen can submit an urgent emergency rescue assistance request', function () {
    $payload = [
        'reporter_name' => 'Carlos Mendoza',
        'contact_phone' => '+63 918 333 4444',
        'country' => 'Philippines',
        'location' => 'Rooftop, 15 Magnolia St, Marikina City',
        'latitude' => 14.64,
        'longitude' => 121.09,
        'people_count' => 6,
        'has_medical_emergency' => true,
        'urgency' => RescueRequest::URGENCY_CRITICAL,
        'situation_description' => 'Fast rising river inundation. Two senior citizens trapped on roof.',
    ];

    $response = $this->actingAs($this->user)->postJson('/rescue-requests', $payload);

    $response->assertCreated()
        ->assertJson(['success' => true]);

    $this->assertDatabaseHas('rescue_requests', [
        'reporter_name' => 'Carlos Mendoza',
        'people_count' => 6,
        'has_medical_emergency' => true,
        'urgency' => 'critical',
        'status' => 'pending',
    ]);
});

test('admin can assign volunteer and dispatch rescue mission', function () {
    $volunteer = Volunteer::create([
        'name' => 'Sgt. Alex Cruz',
        'email' => 'alex.cruz@rescue.ph',
        'phone' => '+63 919 888 7777',
        'country' => 'Philippines',
        'city' => 'Manila',
        'skills' => ['Search & Rescue', 'Boat Operation'],
        'availability_status' => Volunteer::STATUS_AVAILABLE,
    ]);

    $rescue = RescueRequest::create([
        'reporter_name' => 'Maria Santos',
        'contact_phone' => '+63 920 111 2222',
        'country' => 'Philippines',
        'location' => 'Flooded Compound, Pasig City',
        'people_count' => 4,
        'has_medical_emergency' => false,
        'urgency' => RescueRequest::URGENCY_HIGH,
        'status' => RescueRequest::STATUS_PENDING,
        'situation_description' => 'Water 1.5m high, elderly resident needs evacuation.',
    ]);

    $updatePayload = [
        'status' => RescueRequest::STATUS_DISPATCHED,
        'assigned_volunteer_id' => $volunteer->id,
        'resolution_notes' => 'Inflatable rescue craft deployed from Pasig Command.',
    ];

    $response = $this->actingAs($this->admin)->patchJson("/admin/rescue-requests/{$rescue->id}/status", $updatePayload);

    $response->assertOk()
        ->assertJson(['success' => true]);

    $rescue->refresh();
    $volunteer->refresh();

    expect($rescue->status)->toBe(RescueRequest::STATUS_DISPATCHED)
        ->and($rescue->assigned_volunteer_id)->toBe($volunteer->id)
        ->and($volunteer->availability_status)->toBe(Volunteer::STATUS_DEPLOYED);
});
