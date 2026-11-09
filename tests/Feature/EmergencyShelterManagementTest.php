<?php

use App\Models\Shelter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->regularUser = User::factory()->create(['role' => 'user']);
});

test('admin can register a new emergency shelter with capacity and facilities', function () {
    $payload = [
        'name' => 'Cebu City Coliseum Evacuation Center',
        'type' => Shelter::TYPE_EVACUATION_CENTER,
        'country' => 'Philippines',
        'city' => 'Cebu City',
        'address' => 'Sanciangko St, Cebu City',
        'latitude' => 10.2980,
        'longitude' => 123.8960,
        'contact_name' => 'Maria Fernandez',
        'contact_phone' => '+63 32 255 1234',
        'contact_email' => 'cebu.evac@cebucity.gov.ph',
        'total_capacity' => 1500,
        'current_occupancy' => 300,
        'status' => Shelter::STATUS_OPEN,
        'facilities' => ['Medical Triage', 'Clean Potable Water', 'Backup Generator'],
        'notes' => 'Central coastal evacuation refuge for Central Visayas.',
    ];

    $response = $this->actingAs($this->admin)->postJson('/admin/shelters', $payload);

    $response->assertCreated()
        ->assertJson(['success' => true]);

    $this->assertDatabaseHas('shelters', [
        'name' => 'Cebu City Coliseum Evacuation Center',
        'country' => 'Philippines',
        'total_capacity' => 1500,
        'current_occupancy' => 300,
    ]);

    $shelter = Shelter::where('name', 'Cebu City Coliseum Evacuation Center')->first();
    expect($shelter->available_capacity)->toBe(1200)
        ->and($shelter->occupancy_rate)->toBe(20.0);
});

test('non-admin user is forbidden from registering shelters', function () {
    $payload = [
        'name' => 'Unauthorized Shelter',
        'type' => Shelter::TYPE_SHELTER,
        'country' => 'Thailand',
        'city' => 'Bangkok',
        'address' => 'Sukhumvit Rd',
        'latitude' => 13.73,
        'longitude' => 100.56,
        'contact_name' => 'John Doe',
        'contact_phone' => '+66 2 123 4567',
        'total_capacity' => 200,
        'current_occupancy' => 0,
        'status' => Shelter::STATUS_OPEN,
    ];

    $response = $this->actingAs($this->regularUser)->postJson('/admin/shelters', $payload);
    $response->assertForbidden();
});

test('admin can update shelter occupancy and operational status', function () {
    $shelter = Shelter::create([
        'name' => 'Davao Sports Dome',
        'type' => Shelter::TYPE_SHELTER,
        'country' => 'Philippines',
        'city' => 'Davao City',
        'address' => 'Quimpo Blvd',
        'latitude' => 7.07,
        'longitude' => 125.61,
        'contact_name' => 'Pedro Cruz',
        'contact_phone' => '+63 82 222 3344',
        'total_capacity' => 800,
        'current_occupancy' => 750,
        'status' => Shelter::STATUS_OPEN,
    ]);

    $updatePayload = [
        'current_occupancy' => 800,
        'status' => Shelter::STATUS_FULL,
        'notes' => 'At full capacity; redirecting new arrivals to secondary center.',
    ];

    $response = $this->actingAs($this->admin)->putJson("/admin/shelters/{$shelter->id}", $updatePayload);

    $response->assertOk()
        ->assertJson(['success' => true]);

    $shelter->refresh();
    expect($shelter->status)->toBe(Shelter::STATUS_FULL)
        ->and($shelter->available_capacity)->toBe(0)
        ->and($shelter->occupancy_rate)->toBe(100.0);
});

test('sanitizes malicious script tags from shelter submission', function () {
    $payload = [
        'name' => 'Shelter <script>alert("XSS")</script> Safe',
        'type' => Shelter::TYPE_SHELTER,
        'country' => 'Indonesia',
        'city' => 'Surabaya',
        'address' => 'Jl. Pemuda <script>bad()</script>',
        'latitude' => -7.25,
        'longitude' => 112.75,
        'contact_name' => 'Officer Safe',
        'contact_phone' => '+62 31 123 4567',
        'total_capacity' => 500,
        'current_occupancy' => 10,
        'status' => Shelter::STATUS_OPEN,
        'notes' => '<script>steal()</script>Clean notes.',
    ];

    $response = $this->actingAs($this->admin)->postJson('/admin/shelters', $payload);
    $response->assertCreated();

    $this->assertDatabaseMissing('shelters', ['name' => 'Shelter <script>alert("XSS")</script> Safe']);
    $this->assertDatabaseHas('shelters', [
        'name' => 'Shelter  Safe',
        'notes' => 'Clean notes.',
    ]);
});
