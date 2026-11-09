<?php

use App\Services\Disaster\DisasterAnalyticsService;
use App\Services\Disaster\DisasterRiskAssessmentService;

beforeEach(function () {
    $this->riskService = new DisasterRiskAssessmentService();
    $this->analyticsService = new DisasterAnalyticsService();
});

test('computes disaster analytics across all 11 ASEAN nations', function () {
    $events = [
        [
            'id' => 'evt_1',
            'category' => 'earthquake',
            'country' => 'Indonesia',
            'magnitude' => 6.2,
            'event_time' => '2026-10-15T00:00:00Z',
            'sources' => ['USGS'],
        ],
        [
            'id' => 'evt_2',
            'category' => 'cyclone',
            'country' => 'Philippines',
            'severity_level' => 'Red',
            'event_time' => '2026-11-01T00:00:00Z',
            'sources' => ['GDACS'],
        ],
        [
            'id' => 'evt_3',
            'category' => 'flood',
            'country' => 'Vietnam',
            'severity_level' => 'Orange',
            'event_time' => '2026-11-05T00:00:00Z',
            'sources' => ['GDACS'],
        ],
    ];

    $weather = [
        [
            'country' => 'Indonesia',
            'city' => 'Jakarta',
            'temperature_c' => 29.5,
            'condition' => 'Thunderstorm',
            'precipitation_mm' => 12.0,
            'wind_speed_kmh' => 20.0,
        ],
        [
            'country' => 'Philippines',
            'city' => 'Manila',
            'temperature_c' => 31.0,
            'condition' => 'Tropical Storm',
            'precipitation_mm' => 45.0,
            'wind_speed_kmh' => 65.0,
        ],
    ];

    $assessed = $this->riskService->assessEvents($events, $weather);
    $analytics = $this->analyticsService->getAnalytics($assessed, $weather);

    expect($analytics['overview']['total_events'])->toBe(3)
        ->and($analytics['country_frequency'])->toHaveCount(11) // all 11 ASEAN nations present
        ->and($analytics['type_distribution'])->not->toBeEmpty()
        ->and($analytics['monthly_trends'])->not->toBeEmpty()
        ->and($analytics['asean_comparison_matrix'])->toHaveCount(11);

    // Verify Manila weather alert in comparison matrix
    $phMatrix = collect($analytics['asean_comparison_matrix'])->firstWhere('country', 'Philippines');
    expect($phMatrix)->not->toBeNull()
        ->and($phMatrix['ndma_agency'])->toContain('NDRRMC')
        ->and($phMatrix['hotline'])->toContain('911')
        ->and($phMatrix['capital_weather']['alert_level'])->toBe('Severe Warning');
});
