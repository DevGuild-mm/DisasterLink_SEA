<?php

use App\Models\EmergencyResource;
use App\Models\Shelter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['modules.coordination' => true]);
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->shelter = Shelter::create([
        'name' => 'Metro Manila Base Depot',
        'type' => Shelter::TYPE_RELIEF_CENTER,
        'country' => 'Philippines',
        'city' => 'Manila',
        'address' => 'Port Area, Manila',
        'latitude' => 14.59,
        'longitude' => 120.97,
        'contact_name' => 'Commander Cruz',
        'contact_phone' => '+63 2 8527 1234',
        'total_capacity' => 1000,
        'current_occupancy' => 0,
        'status' => Shelter::STATUS_OPEN,
    ]);
});

test('admin can create emergency resource inventory item with automatic status computation', function () {
    $payload = [
        'shelter_id' => $this->shelter->id,
        'category' => EmergencyResource::CATEGORY_WATER_FOOD,
        'item_name' => 'MRE Emergency Food Packs',
        'quantity' => 200,
        'unit' => 'packs',
        'min_threshold' => 100,
        'notes' => 'Non-perishable shelf-stable rations.',
    ];

    $response = $this->actingAs($this->admin)->postJson('/admin/resources', $payload);

    $response->assertCreated()
        ->assertJson(['success' => true]);

    $this->assertDatabaseHas('emergency_resources', [
        'item_name' => 'MRE Emergency Food Packs',
        'status' => EmergencyResource::STATUS_ADEQUATE,
    ]);
});

test('auto-computes critical status when quantity falls below half of threshold', function () {
    $resource = EmergencyResource::create([
        'shelter_id' => $this->shelter->id,
        'category' => EmergencyResource::CATEGORY_MEDICAL,
        'item_name' => 'Blood Plasma Units',
        'quantity' => 20,
        'unit' => 'units',
        'min_threshold' => 100, // 20 is < 50 (50% of threshold) => critical
    ]);

    expect($resource->status)->toBe(EmergencyResource::STATUS_CRITICAL)
        ->and($resource->is_shortage)->toBeTrue();
});

test('recording resource distribution decrements stock and logs recipient', function () {
    $resource = EmergencyResource::create([
        'shelter_id' => $this->shelter->id,
        'category' => EmergencyResource::CATEGORY_WATER_FOOD,
        'item_name' => 'Bottled Mineral Water',
        'quantity' => 500,
        'unit' => 'liters',
        'min_threshold' => 100,
    ]);

    $distPayload = [
        'recipient' => 'Barangay 102 Evacuees',
        'quantity' => 150,
        'distributed_by' => 'Officer Reyes',
        'notes' => 'Distributed to 45 displaced residents.',
    ];

    $response = $this->actingAs($this->admin)->postJson("/admin/resources/{$resource->id}/distribute", $distPayload);

    $response->assertCreated()
        ->assertJson(['success' => true, 'remaining_stock' => 350]);

    $resource->refresh();
    expect($resource->quantity)->toBe(350);

    $this->assertDatabaseHas('resource_distributions', [
        'resource_id' => $resource->id,
        'recipient' => 'Barangay 102 Evacuees',
        'quantity' => 150,
        'distributed_by' => 'Officer Reyes',
    ]);
});

test('rejects distribution if requested quantity exceeds available inventory', function () {
    $resource = EmergencyResource::create([
        'shelter_id' => $this->shelter->id,
        'category' => EmergencyResource::CATEGORY_RESCUE_EQUIPMENT,
        'item_name' => 'Rescue Drones',
        'quantity' => 5,
        'unit' => 'units',
        'min_threshold' => 2,
    ]);

    $distPayload = [
        'recipient' => 'Search Team Delta',
        'quantity' => 10, // exceeds available 5
        'distributed_by' => 'Officer Reyes',
    ];

    $response = $this->actingAs($this->admin)->postJson("/admin/resources/{$resource->id}/distribute", $distPayload);

    $response->assertStatus(422)
        ->assertJson(['success' => false]);

    $resource->refresh();
    expect($resource->quantity)->toBe(5); // unchanged
});
