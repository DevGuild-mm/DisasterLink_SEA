<?php

use App\Services\Disaster\DisasterRiskAssessmentService;

beforeEach(function () {
    $this->riskService = new DisasterRiskAssessmentService();
});

test('classifies major shallow earthquake as critical risk', function () {
    $event = [
        'category' => 'earthquake',
        'raw_type' => 'Earthquake',
        'title' => 'M 7.4 - Banda Sea, Indonesia',
        'country' => 'Indonesia',
        'magnitude' => 7.4,
        'depth_km' => 15.0,
        'severity_level' => 'Red',
        'sources' => ['USGS', 'GDACS'],
    ];

    $weatherByCountry = [
        'indonesia' => [
            'country' => 'Indonesia',
            'precipitation_mm' => 25.0,
            'wind_speed_kmh' => 20.0,
        ],
    ];

    $assessment = $this->riskService->assessEvent($event, $weatherByCountry);

    expect($assessment['level'])->toBe(DisasterRiskAssessmentService::LEVEL_CRITICAL)
        ->and($assessment['score'])->toBeGreaterThanOrEqual(75)
        ->and($assessment['badge_color'])->toBe('rose')
        ->and($assessment['is_critical'])->toBeTrue()
        ->and($assessment['breakdown']['hazard_severity'])->toBeGreaterThanOrEqual(30)
        ->and($assessment['factors'])->not->toBeEmpty();
});

test('classifies moderate storm in low-vulnerability area as moderate risk', function () {
    $event = [
        'category' => 'storm',
        'raw_type' => 'Severe Storm',
        'title' => 'Moderate Tropical Depression',
        'country' => 'Singapore',
        'severity_level' => 'Green',
        'sources' => ['NASA EONET'],
    ];

    $weatherByCountry = [
        'singapore' => [
            'country' => 'Singapore',
            'precipitation_mm' => 6.0,
            'wind_speed_kmh' => 22.0,
        ],
    ];

    $assessment = $this->riskService->assessEvent($event, $weatherByCountry);

    expect($assessment['level'])->toBeIn([DisasterRiskAssessmentService::LEVEL_MODERATE, DisasterRiskAssessmentService::LEVEL_LOW])
        ->and($assessment['score'])->toBeLessThan(60);
});

test('accounts for environmental compounding when heavy rain occurs', function () {
    $event = [
        'category' => 'flood',
        'title' => 'Inundation in Mekong Basin',
        'country' => 'Vietnam',
        'severity_level' => 'Orange',
        'sources' => ['GDACS'],
    ];

    $weatherWithHeavyRain = [
        'vietnam' => [
            'country' => 'Vietnam',
            'precipitation_mm' => 55.0,
            'wind_speed_kmh' => 65.0,
        ],
    ];

    $weatherDry = [
        'vietnam' => [
            'country' => 'Vietnam',
            'precipitation_mm' => 0.0,
            'wind_speed_kmh' => 10.0,
        ],
    ];

    $assessmentHeavy = $this->riskService->assessEvent($event, $weatherWithHeavyRain);
    $assessmentDry = $this->riskService->assessEvent($event, $weatherDry);

    expect($assessmentHeavy['breakdown']['environmental_risk'])
        ->toBeGreaterThan($assessmentDry['breakdown']['environmental_risk'])
        ->and($assessmentHeavy['score'])->toBeGreaterThan($assessmentDry['score']);
});

test('batch assesses multiple events and embeds risk assessment objects', function () {
    $events = [
        [
            'id' => 'evt_1',
            'category' => 'earthquake',
            'country' => 'Philippines',
            'magnitude' => 6.8,
            'depth_km' => 10.0,
            'sources' => ['USGS'],
        ],
        [
            'id' => 'evt_2',
            'category' => 'wildfire',
            'country' => 'Cambodia',
            'sources' => ['NASA EONET'],
        ],
    ];

    $assessed = $this->riskService->assessEvents($events);

    expect($assessed)->toHaveCount(2)
        ->and($assessed[0]['risk_assessment'])->toHaveKeys(['score', 'level', 'breakdown', 'factors'])
        ->and($assessed[1]['risk_assessment'])->toHaveKeys(['score', 'level', 'breakdown', 'factors']);
});
