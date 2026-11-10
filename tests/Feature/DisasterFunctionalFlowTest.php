<?php

use App\Models\DisasterReport;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    config(['modules.countries' => true, 'modules.intelligence' => true, 'modules.coordination' => true, 'modules.reports' => true]);
    Cache::flush();
});

function fakeFullTelemetry(): void
{
    seedDashboardData([
        ['iso3' => 'PHL', 'external_id' => 'TC:2001', 'event_type' => 'cyclone', 'title' => 'Tropical Cyclone in Luzon', 'alert_level' => 'Red', 'latitude' => 14.6, 'longitude' => 121.0],
    ]);
    markSourcesSynced();
}

test('1. overview page renders successfully with initial telemetry', function () {
    fakeFullTelemetry();

    $response = $this->get('/overview');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Overview')
        ->has('initialDashboardData.events')
        ->has('initialDashboardData.summary')
    );
});

test('2. interactive disaster map page renders with geospatial layers', function () {
    fakeFullTelemetry();

    $response = $this->get('/map');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('MapPage')
        ->has('initialDashboardData.events')
        ->has('initialDashboardData.countries')
    );
});

test('3. live events feed page renders with filter options', function () {
    fakeFullTelemetry();

    $response = $this->get('/events');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('EventsPage')
        ->has('initialDashboardData.events')
        ->has('initialDashboardData.categories')
    );
});

test('4. event detail page resolves existing event and displays specifications', function () {
    fakeFullTelemetry();

    $response = $this->get('/events/gdacs_TC:2001');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('EventDetailPage')
        ->where('eventId', 'gdacs_TC:2001')
        ->where('event.source_id', 'TC:2001')
        ->where('event.country', 'Philippines')
        ->has('event.timeline')
    );
});

test('5. weather page renders all ASEAN capital city observations', function () {
    fakeFullTelemetry();

    $response = $this->get('/weather');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('WeatherPage')
        ->has('initialDashboardData.weather')
    );
});

test('6. data freshness page renders provider connectivity statuses', function () {
    fakeFullTelemetry();

    $response = $this->get('/freshness');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('FreshnessPage')
        ->has('initialDashboardData.sources.gdacs')
        ->has('initialDashboardData.sources.usgs')
        ->has('initialDashboardData.sources.weather')
    );
});

test('7. disaster report input sanitization strips XSS and malicious HTML tags', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/reports', [
        'disaster_type' => 'Flood',
        'title' => '<script>alert("XSS")</script>Flash flood near bridge',
        'description' => '<b>Heavy flooding</b> and <img src=x onerror=alert(1)>overflowing drain.',
        'country' => 'Philippines',
        'location' => '<script>steal()</script>Pasig City',
        'incident_time' => Carbon::now()->subHours(2)->format('Y-m-d\TH:i'),
        'latitude' => 14.5764,
        'longitude' => 121.0851,
    ]);

    $response->assertRedirect(route('reports.my'));

    $report = DisasterReport::where('user_id', $user->id)->first();
    expect($report)->not->toBeNull();
    expect($report->title)->toBe('Flash flood near bridge');
    expect($report->description)->toBe('Heavy flooding and overflowing drain.');
    expect($report->location)->toBe('Pasig City');
});

test('8. admin can review, verify, and view community report on event detail page', function () {
    fakeFullTelemetry();

    $admin = User::factory()->create(['role' => 'admin']);
    $user = User::factory()->create(['name' => 'Maria Santos']);

    $report = DisasterReport::create([
        'user_id' => $user->id,
        'disaster_type' => 'Flood',
        'title' => 'Marikina River Alert Level 2',
        'description' => 'Water levels rising rapidly due to mountain runoff.',
        'country' => 'Philippines',
        'location' => 'Marikina River Park',
        'incident_time' => Carbon::now()->subHours(1),
        'latitude' => 14.6507,
        'longitude' => 121.1029,
        'status' => DisasterReport::STATUS_PENDING,
    ]);

    // Admin verifies report with notes
    $verifyResp = $this->actingAs($admin)->patch("/admin/reports/{$report->id}/status", [
        'status' => 'verified',
        'review_notes' => '<b>Verified with local CDRRMO sensor</b>',
    ]);
    $verifyResp->assertSessionHas('success');

    $freshReport = $report->fresh();
    expect($freshReport->status)->toBe('verified');
    expect($freshReport->review_notes)->toBe('Verified with local CDRRMO sensor');

    // Access verified event via public event detail page
    $detailResp = $this->get("/events/{$report->id}");
    $detailResp->assertOk();
    $detailResp->assertInertia(fn ($page) => $page
        ->component('EventDetailPage')
        ->where('event.id', $report->id)
        ->where('event.is_community_report', true)
        ->where('event.submitter_name', 'Maria Santos')
        ->where('event.country', 'Philippines')
    );
});

test('9. rate limiting protects public API endpoints against request flooding', function () {
    fakeFullTelemetry();

    // 60 requests are allowed by throttle:60,1
    $response = $this->getJson('/api/disaster-dashboard');
    $response->assertOk();
    $response->assertHeader('X-RateLimit-Limit', '60');
    expect((int) $response->headers->get('X-RateLimit-Remaining'))->toBeGreaterThanOrEqual(0);
});
