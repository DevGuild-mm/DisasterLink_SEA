<?php

use App\Models\User;
use App\Services\Disaster\GdacsService;
use App\Services\Disaster\NasaEonetService;
use App\Services\Disaster\OpenMeteoService;
use App\Services\Disaster\UsgsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Mock upstream API services to prevent live HTTP calls during test runs
    $mockMeta = fn (string $source) => [
        'source' => $source,
        'name' => strtoupper($source),
        'status' => 'Live',
        'is_fresh' => true,
        'is_stale' => false,
        'last_successful_fetch' => '2026-11-09T00:00:00Z',
        'latest_event_time' => '2026-11-09T00:00:00Z',
        'documentation_url' => 'https://example.com',
    ];

    $mockEvents = [
        [
            'id' => 'usgs_quake_101',
            'source' => 'USGS',
            'sources' => ['USGS'],
            'source_id' => '101',
            'category' => 'earthquake',
            'raw_type' => 'Earthquake',
            'title' => 'M 6.6 - Davao Oriental, Philippines',
            'description' => 'Strong earthquake near coast',
            'country' => 'Philippines',
            'location' => 'Davao Oriental',
            'latitude' => 6.8,
            'longitude' => 126.5,
            'coordinates' => [6.8, 126.5],
            'magnitude' => 6.6,
            'depth_km' => 22.0,
            'severity' => 'Red',
            'severity_level' => 'Red',
            'event_date' => '2026-11-09',
            'event_time' => '2026-11-09T05:00:00Z',
            'source_url' => 'https://earthquake.usgs.gov/earthquakes/eventpage/101',
        ],
    ];

    $this->mock(GdacsService::class, fn ($m) => $m->shouldReceive('getEvents')->andReturn(['events' => [], 'meta' => $mockMeta('GDACS')]));
    $this->mock(UsgsService::class, fn ($m) => $m->shouldReceive('getEvents')->andReturn(['events' => $mockEvents, 'meta' => $mockMeta('USGS')]));
    $this->mock(NasaEonetService::class, fn ($m) => $m->shouldReceive('getEvents')->andReturn(['events' => [], 'meta' => $mockMeta('NASA')]));
    $this->mock(OpenMeteoService::class, fn ($m) => $m->shouldReceive('getWeather')->andReturn([
        'weather' => [
            [
                'country' => 'Philippines',
                'city' => 'Manila',
                'iso2' => 'PH',
                'iso3' => 'PHL',
                'latitude' => 14.5995,
                'longitude' => 120.9842,
                'temperature_c' => 30.2,
                'apparent_temperature_c' => 34.0,
                'humidity_pct' => 80,
                'precipitation_mm' => 18.0,
                'wind_speed_kmh' => 45.0,
                'wind_direction_deg' => 90,
                'weather_code' => 80,
                'condition' => 'Rain Showers',
                'icon' => 'cloud-rain',
                'is_day' => true,
                'forecast_max_c' => 32.0,
                'forecast_min_c' => 25.0,
                'forecast_precipitation_sum_mm' => 25.0,
                'observation_time' => '2026-11-09T05:00:00Z',
                'data_type' => 'High-resolution atmospheric model',
            ],
        ],
        'meta' => $mockMeta('OpenMeteo'),
    ]));
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
