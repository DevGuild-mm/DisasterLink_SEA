<?php

use App\Services\Disaster\DisasterRiskAssessmentService;
use App\Services\Disaster\EarlyWarningService;

beforeEach(function () {
    $this->riskService = new DisasterRiskAssessmentService();
    $this->warningService = new EarlyWarningService();
});

test('detects qualifying high-risk events and generates early warning alerts', function () {
    $events = [
        [
            'id' => 'evt_quake_ph',
            'category' => 'earthquake',
            'title' => 'M 6.7 Earthquake - Mindanao',
            'country' => 'Philippines',
            'location' => 'Mindanao',
            'latitude' => 7.5,
            'longitude' => 125.8,
            'magnitude' => 6.7,
            'depth_km' => 15.0,
            'event_time' => '2026-11-09T10:00:00Z',
            'sources' => ['USGS'],
        ],
        [
            'id' => 'evt_minor_sg',
            'category' => 'other',
            'title' => 'Minor Localized Incident',
            'country' => 'Singapore',
            'latitude' => 1.3,
            'longitude' => 103.8,
            'event_time' => '2026-11-09T10:00:00Z',
            'sources' => ['Community Report'],
        ],
    ];

    $assessed = $this->riskService->assessEvents($events);
    $result = $this->warningService->generateAlerts($assessed);

    expect($result['total_alerts'])->toBeGreaterThanOrEqual(1)
        ->and($result['alerts'][0]['category'])->toBe('earthquake')
        ->and($result['alerts'][0]['country'])->toBe('Philippines')
        ->and($result['alerts'][0]['emergency_headline'])->toContain('SEISMIC')
        ->and($result['alerts'][0]['immediate_action'])->toContain('Drop, Cover')
        ->and($result['alerts'][0]['protective_actions'])->not->toBeEmpty();
});

test('deduplicates alerts sharing same hazard, coordinates, and date window', function () {
    $events = [
        [
            'id' => 'evt_quake_usgs',
            'category' => 'earthquake',
            'title' => 'USGS M 6.5 - Java Trench',
            'country' => 'Indonesia',
            'latitude' => -8.2,
            'longitude' => 110.5,
            'magnitude' => 6.5,
            'event_date' => '2026-11-09',
            'event_time' => '2026-11-09T08:00:00Z',
            'sources' => ['USGS'],
        ],
        [
            'id' => 'evt_quake_gdacs',
            'category' => 'earthquake',
            'title' => 'GDACS M 6.5 - Java Trench duplicate',
            'country' => 'Indonesia',
            'latitude' => -8.2,
            'longitude' => 110.5,
            'magnitude' => 6.5,
            'event_date' => '2026-11-09',
            'event_time' => '2026-11-09T08:02:00Z',
            'sources' => ['GDACS'],
        ],
    ];

    $assessed = $this->riskService->assessEvents($events);
    $result = $this->warningService->generateAlerts($assessed);

    expect($result['total_alerts'])->toBe(1);
});

test('supports configurable alert thresholds by country and minimum severity', function () {
    $events = [
        [
            'id' => 'evt_1',
            'category' => 'earthquake',
            'country' => 'Indonesia',
            'magnitude' => 7.2,
            'latitude' => -0.5,
            'longitude' => 120.0,
            'event_time' => '2026-11-09T00:00:00Z',
            'sources' => ['USGS'],
        ],
        [
            'id' => 'evt_2',
            'category' => 'flood',
            'country' => 'Thailand',
            'latitude' => 14.5,
            'longitude' => 100.5,
            'event_time' => '2026-11-09T00:00:00Z',
            'sources' => ['GDACS'],
        ],
    ];

    $assessed = $this->riskService->assessEvents($events);

    // Filter by country = Thailand
    $thailandAlerts = $this->warningService->generateAlerts($assessed, [], [
        'country' => 'Thailand',
        'min_level' => 'all',
    ]);
    expect($thailandAlerts['alerts'])->toHaveCount(1)
        ->and($thailandAlerts['alerts'][0]['country'])->toBe('Thailand');

    // Filter by min_magnitude = 7.0
    $magAlerts = $this->warningService->generateAlerts($assessed, [], [
        'min_magnitude' => 7.0,
    ]);
    expect($magAlerts['alerts'])->toHaveCount(1)
        ->and($magAlerts['alerts'][0]['country'])->toBe('Indonesia');
});
