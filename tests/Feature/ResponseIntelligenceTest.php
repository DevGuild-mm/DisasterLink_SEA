<?php

use App\Models\DisasterReport;
use App\Models\User;
use App\Services\Disaster\ResponseIntelligenceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->responseService = new ResponseIntelligenceService();
    $this->user = User::factory()->create();
});

test('triages and prioritizes rapid-onset disaster reports near warning zones', function () {
    $report1 = DisasterReport::create([
        'user_id' => $this->user->id,
        'disaster_type' => 'Earthquake',
        'title' => 'Structural Collapse after Strong Tremor',
        'description' => 'Bridge section cracked, immediate assistance required.',
        'country' => 'Indonesia',
        'location' => 'Palu, Central Sulawesi',
        'latitude' => -0.89,
        'longitude' => 119.87,
        'incident_time' => Carbon::now()->subHour(),
        'photos' => ['reports/test1.jpg'],
        'status' => DisasterReport::STATUS_PENDING,
    ]);

    $report2 = DisasterReport::create([
        'user_id' => $this->user->id,
        'disaster_type' => 'Other',
        'title' => 'Minor Fallen Tree Branch',
        'description' => 'Fallen branch on sidewalk.',
        'country' => 'Singapore',
        'location' => 'Jurong East',
        'latitude' => 1.33,
        'longitude' => 103.74,
        'incident_time' => Carbon::now()->subDays(2),
        'status' => DisasterReport::STATUS_PENDING,
    ]);

    $activeAlerts = [
        [
            'id' => 'alert_1',
            'title' => 'M 6.8 Palu Quake Alert',
            'latitude' => -0.90,
            'longitude' => 119.85,
            'country' => 'Indonesia',
        ],
    ];

    $result = $this->responseService->triageReports([$report1, $report2], $activeAlerts);

    expect($result['total_reports'])->toBe(2)
        ->and($result['immediate_count'])->toBeGreaterThanOrEqual(1)
        ->and($result['reports'][0]['id'])->toBe($report1->id) // report1 scored higher
        ->and($result['reports'][0]['priority_tier'])->toBe(ResponseIntelligenceService::PRIORITY_IMMEDIATE)
        ->and($result['reports'][0]['nearest_alert_distance_km'])->toBeLessThan(15.0)
        ->and($result['reports'][0]['recommended_action'])->toContain('dispatch');
});
