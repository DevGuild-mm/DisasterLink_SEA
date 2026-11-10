<?php

use App\Models\CapitalWeather;
use App\Models\Country;
use App\Models\DisasterEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['modules.intelligence' => true]);
    // The pages read MySQL only, so seed a stored quake and a capital observation.
    $philippines = Country::factory()->create(['iso2' => 'PH', 'iso3' => 'PHL', 'name' => 'Philippines', 'capital_name' => 'Manila']);

    DisasterEvent::factory()->usgsEarthquake(6.6)->create([
        'external_id' => 'us101',
        'title' => 'M 6.6 - Davao Oriental, Philippines',
        'location_text' => 'Davao Oriental',
        'latitude' => 6.8,
        'longitude' => 126.5,
        'depth_km' => 22,
        'occurred_at' => now()->subHour(),
        'country_id' => $philippines->id,
        'country_status' => 'resolved',
    ]);

    CapitalWeather::factory()->create(['country_id' => $philippines->id, 'temperature_c' => 30.2, 'precipitation_mm' => 18.0]);
});

test('GET /intelligence renders Inertia IntelligencePage with telemetry payload', function () {
    $response = $this->get('/intelligence');

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('IntelligencePage')
            ->has('intelligence')
            ->has('intelligence.summary')
            ->has('intelligence.early_warnings')
            ->has('intelligence.analytics')
            ->has('intelligence.response_intelligence')
            ->has('initialDashboardData')
            ->has('filters')
        );
});

test('GET /api/intelligence returns JSON payload with warnings and analytics', function () {
    $response = $this->getJson('/api/intelligence?country=Philippines');

    $response->assertOk()
        ->assertJsonStructure([
            'generated_at',
            'summary' => [
                'total_events',
                'active_alerts',
                'critical_alerts',
                'high_alerts',
                'triaged_reports',
            ],
            'early_warnings' => [
                'total_alerts',
                'alerts',
            ],
            'analytics' => [
                'overview',
                'type_distribution',
                'country_frequency',
                'asean_comparison_matrix',
            ],
            'response_intelligence',
            'countries',
        ]);
});
