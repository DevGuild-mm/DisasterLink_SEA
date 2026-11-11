<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
});

dataset('parkedGetRoutes', [
    'intelligence page' => ['/intelligence'],
    'intelligence api' => ['/api/intelligence'],
    'coordination page' => ['/coordination'],
    'coordination api' => ['/api/coordination'],
    'shelters api' => ['/api/shelters'],
    'countries page' => ['/countries'],
    'country page' => ['/countries/thailand'],
    'countries api' => ['/api/countries'],
    'report form' => ['/reports/create'],
    'my reports' => ['/my-reports'],
    'admin reports' => ['/admin/reports'],
]);

test('parked module GET routes return 404 when the flag is off', function (string $uri) {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->get($uri)->assertNotFound();
})->with('parkedGetRoutes');

test('parked module write routes return 404 when the flag is off', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->post('/volunteers/register', [])->assertNotFound();
    $this->post('/rescue-requests', [])->assertNotFound();
    $this->actingAs($admin)->post('/reports', [])->assertNotFound();
    $this->actingAs($admin)->post('/admin/shelters', [])->assertNotFound();
});
