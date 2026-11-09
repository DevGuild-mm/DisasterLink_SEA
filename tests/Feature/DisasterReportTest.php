<?php

use App\Models\DisasterReport;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Cache::flush();
});

function fakeExternalApis(): void
{
    Http::fake([
        'www.gdacs.org/*' => Http::response(['type' => 'FeatureCollection', 'features' => []]),
        'earthquake.usgs.gov/*' => Http::response(['type' => 'FeatureCollection', 'features' => []]),
        'eonet.gsfc.nasa.gov/*' => Http::response(['events' => []]),
        'api.open-meteo.com/*' => Http::response([
            'current' => [
                'time' => '2026-11-08T06:00',
                'temperature_2m' => 28.5,
                'apparent_temperature' => 31.2,
                'relative_humidity_2m' => 80,
                'precipitation' => 0.0,
                'weather_code' => 1,
                'wind_speed_10m' => 12.0,
                'wind_direction_10m' => 180,
                'is_day' => 1,
            ],
            'daily' => [
                'time' => ['2026-11-08'],
                'temperature_2m_max' => [32.0],
                'temperature_2m_min' => [25.0],
                'precipitation_sum' => [1.5],
            ],
        ]),
    ]);
}

test('1. guest cannot access reports create page and is redirected to login', function () {
    $response = $this->get('/reports/create');
    $response->assertRedirect(route('login'));
});

test('2. authenticated user can submit a valid disaster report with coordinates and photos', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    // Valid 1x1 PNG image bytes (does not require PHP GD extension)
    $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
    $photo = UploadedFile::fake()->createWithContent('flood_evidence.png', $pngBytes);

    $response = $this->actingAs($user)->post('/reports', [
        'disaster_type' => 'Flood',
        'title' => 'Flash Flooding in Downtown Davao City',
        'description' => 'Heavy rainfall caused river swelling and knee-deep flood waters on main roads.',
        'country' => 'Philippines',
        'location' => 'Matina Crossing, Davao City',
        'incident_time' => Carbon::now()->subHours(2)->format('Y-m-d\TH:i'),
        'latitude' => 7.0731,
        'longitude' => 125.6128,
        'photos' => [$photo],
    ]);

    $response->assertRedirect(route('reports.my'));
    $response->assertSessionHas('success');

    $this->assertDatabaseHas('disaster_reports', [
        'user_id' => $user->id,
        'disaster_type' => 'Flood',
        'title' => 'Flash Flooding in Downtown Davao City',
        'country' => 'Philippines',
        'status' => DisasterReport::STATUS_PENDING,
    ]);

    $report = DisasterReport::where('user_id', $user->id)->first();
    expect($report)->not->toBeNull();
    expect($report->photos)->toHaveCount(1);
    Storage::disk('public')->assertExists($report->photos[0]);
});

test('3. form validation rejects invalid data including bad coordinates, invalid country and file types', function () {
    $user = User::factory()->create();

    // Test invalid country and bad coordinates
    $response = $this->actingAs($user)->post('/reports', [
        'disaster_type' => 'Flood',
        'title' => 'Test Incident',
        'description' => 'This is a test description.',
        'country' => 'Germany', // Not in ASEAN
        'location' => 'Berlin',
        'incident_time' => Carbon::now()->subHour()->format('Y-m-d\TH:i'),
        'latitude' => 120.0, // Invalid latitude > 90
        'longitude' => 200.0, // Invalid longitude > 180
    ]);

    $response->assertSessionHasErrors(['country', 'latitude', 'longitude']);

    // Test invalid photo file type (e.g., pdf file)
    $badFile = UploadedFile::fake()->create('document.pdf', 100, 'application/pdf');
    $photoResponse = $this->actingAs($user)->post('/reports', [
        'disaster_type' => 'Flood',
        'title' => 'Valid Title Length',
        'description' => 'Valid description with sufficient details.',
        'country' => 'Philippines',
        'location' => 'Valid Location',
        'incident_time' => Carbon::now()->subHour()->format('Y-m-d\TH:i'),
        'photos' => [$badFile],
    ]);

    $photoResponse->assertSessionHasErrors(['photos.0']);
});

test('4. newly created report has pending status and unique prefixed ID', function () {
    $user = User::factory()->create();

    $report = DisasterReport::create([
        'user_id' => $user->id,
        'disaster_type' => 'Storm',
        'title' => 'Typhoon Wind Gusts in Da Nang',
        'description' => 'Strong tropical storm winds damaged roadside billboards.',
        'country' => 'Vietnam',
        'location' => 'Da Nang Coastal Area',
        'incident_time' => Carbon::now()->subHours(3),
        'status' => DisasterReport::STATUS_PENDING,
    ]);

    expect($report->status)->toBe('pending');
    expect($report->id)->toStartWith('rep_');
    expect(strlen($report->id))->toBeGreaterThan(10);
});

test('5. user can view their own reports at my-reports', function () {
    $user = User::factory()->create();

    $report = DisasterReport::create([
        'user_id' => $user->id,
        'disaster_type' => 'Earthquake',
        'title' => 'Tremors felt in Yangon',
        'description' => 'Moderate shaking felt in high-rise buildings for approximately 15 seconds.',
        'country' => 'Myanmar',
        'location' => 'Downtown Yangon',
        'incident_time' => Carbon::now()->subHour(),
        'status' => DisasterReport::STATUS_PENDING,
    ]);

    $response = $this->actingAs($user)->get('/my-reports');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('reports/MyReports')
        ->has('reports', 1)
        ->where('reports.0.id', $report->id)
        ->where('reports.0.title', 'Tremors felt in Yangon')
    );
});

test('6. user cannot view other users reports at my-reports', function () {
    $userA = User::factory()->create(['name' => 'Alice']);
    $userB = User::factory()->create(['name' => 'Bob']);

    $reportA = DisasterReport::create([
        'user_id' => $userA->id,
        'disaster_type' => 'Flood',
        'title' => "Alice's Flood Report",
        'description' => 'Water levels rising rapidly in low-lying district.',
        'country' => 'Thailand',
        'location' => 'Ayutthaya',
        'incident_time' => Carbon::now()->subHours(2),
        'status' => DisasterReport::STATUS_PENDING,
    ]);

    $reportB = DisasterReport::create([
        'user_id' => $userB->id,
        'disaster_type' => 'Wildfire',
        'title' => "Bob's Wildfire Report",
        'description' => 'Haze and active brush fire observed on hillside.',
        'country' => 'Indonesia',
        'location' => 'Sumatra',
        'incident_time' => Carbon::now()->subHours(2),
        'status' => DisasterReport::STATUS_PENDING,
    ]);

    $response = $this->actingAs($userA)->get('/my-reports');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('reports/MyReports')
        ->has('reports', 1)
        ->where('reports.0.id', $reportA->id)
        ->where('reports.0.title', "Alice's Flood Report")
    );
});

test('7. non-admin user cannot access admin reports dashboard (403 Forbidden)', function () {
    $regularUser = User::factory()->create(['role' => 'user']);

    $response = $this->actingAs($regularUser)->get('/admin/reports');

    $response->assertForbidden();
});

test('8. admin user can view all reports at admin reports dashboard', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $submitter = User::factory()->create(['name' => 'Citizen Reporter']);

    DisasterReport::create([
        'user_id' => $submitter->id,
        'disaster_type' => 'Flood',
        'title' => 'Severe Flood in Jakarta Suburb',
        'description' => 'Canal overflowed causing water inundation.',
        'country' => 'Indonesia',
        'location' => 'East Jakarta',
        'incident_time' => Carbon::now()->subHours(4),
        'status' => DisasterReport::STATUS_PENDING,
    ]);

    $response = $this->actingAs($admin)->get('/admin/reports');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('admin/reports/Index')
        ->has('reports.data', 1)
        ->where('reports.data.0.title', 'Severe Flood in Jakarta Suburb')
        ->where('counts.pending', 1)
    );
});

test('9. admin can transition report to under_review', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $submitter = User::factory()->create();

    $report = DisasterReport::create([
        'user_id' => $submitter->id,
        'disaster_type' => 'Landslide',
        'title' => 'Mountain Road Blocked by Landslide',
        'description' => 'Debris and mud blocks both lanes of mountain pass.',
        'country' => 'Laos',
        'location' => 'Luang Prabang Province',
        'incident_time' => Carbon::now()->subHours(5),
        'status' => DisasterReport::STATUS_PENDING,
    ]);

    $response = $this->actingAs($admin)->patch("/admin/reports/{$report->id}/status", [
        'status' => DisasterReport::STATUS_UNDER_REVIEW,
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $fresh = $report->fresh();
    expect($fresh->status)->toBe('under_review');
    expect($fresh->reviewer_id)->toBe($admin->id);
});

test('10. admin can verify report with review notes and sets verified timestamp', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $submitter = User::factory()->create();

    $report = DisasterReport::create([
        'user_id' => $submitter->id,
        'disaster_type' => 'Volcanic Activity',
        'title' => 'Ash plume from Mount Semeru',
        'description' => 'Ash plume estimated at 1,500m above crater summit.',
        'country' => 'Indonesia',
        'location' => 'East Java',
        'incident_time' => Carbon::now()->subHours(1),
        'status' => DisasterReport::STATUS_UNDER_REVIEW,
    ]);

    $response = $this->actingAs($admin)->patch("/admin/reports/{$report->id}/status", [
        'status' => DisasterReport::STATUS_VERIFIED,
        'review_notes' => 'Confirmed with PVMBG geological agency advisory.',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $fresh = $report->fresh();
    expect($fresh->status)->toBe('verified');
    expect($fresh->reviewer_id)->toBe($admin->id);
    expect($fresh->review_notes)->toBe('Confirmed with PVMBG geological agency advisory.');
    expect($fresh->verified_at)->not->toBeNull();
});

test('11. admin can reject report with required rejection reason', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $submitter = User::factory()->create();

    $report = DisasterReport::create([
        'user_id' => $submitter->id,
        'disaster_type' => 'Earthquake',
        'title' => 'Fabricated Minor Shaking',
        'description' => 'Report with conflicting claims and incorrect location.',
        'country' => 'Singapore',
        'location' => 'Orchard',
        'incident_time' => Carbon::now()->subHours(2),
        'status' => DisasterReport::STATUS_PENDING,
    ]);

    $response = $this->actingAs($admin)->patch("/admin/reports/{$report->id}/status", [
        'status' => DisasterReport::STATUS_REJECTED,
        'rejection_reason' => 'Unverifiable observations or conflicting satellite imagery',
    ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    $fresh = $report->fresh();
    expect($fresh->status)->toBe('rejected');
    expect($fresh->rejection_reason)->toBe('Unverifiable observations or conflicting satellite imagery');
    expect($fresh->reviewer_id)->toBe($admin->id);

    // Enforce rule: cannot verify a report that has already been rejected
    $reverifyResponse = $this->actingAs($admin)->patch("/admin/reports/{$report->id}/status", [
        'status' => DisasterReport::STATUS_VERIFIED,
    ]);
    $reverifyResponse->assertSessionHas('error');
    expect($report->fresh()->status)->toBe('rejected');
});

test('12. verified reports appear in disaster dashboard telemetry while unverified reports do not', function () {
    fakeExternalApis();

    $user = User::factory()->create();

    // 1. Pending report (unverified - MUST NOT appear)
    $pendingReport = DisasterReport::create([
        'user_id' => $user->id,
        'disaster_type' => 'Flood',
        'title' => 'Unverified Pending Flood in Bago',
        'description' => 'Water rising near local canal.',
        'country' => 'Myanmar',
        'location' => 'Bago Region',
        'incident_time' => Carbon::now()->subHours(2),
        'status' => DisasterReport::STATUS_PENDING,
    ]);

    // 2. Rejected report (MUST NOT appear)
    $rejectedReport = DisasterReport::create([
        'user_id' => $user->id,
        'disaster_type' => 'Storm',
        'title' => 'Rejected False Storm Report',
        'description' => 'Spam report.',
        'country' => 'Malaysia',
        'location' => 'Penang',
        'incident_time' => Carbon::now()->subHours(3),
        'status' => DisasterReport::STATUS_REJECTED,
        'rejection_reason' => 'Spam, fabricated details, or promotional content',
    ]);

    // 3. Verified report (MUST appear with distinct Community Report labeling)
    $verifiedReport = DisasterReport::create([
        'user_id' => $user->id,
        'disaster_type' => 'Flood',
        'title' => 'Verified Community Flood in Marikina',
        'description' => 'Water reached second alarm level at Marikina river.',
        'country' => 'Philippines',
        'location' => 'Marikina City',
        'incident_time' => Carbon::now()->subHour(),
        'status' => DisasterReport::STATUS_VERIFIED,
        'verified_at' => Carbon::now(),
        'review_notes' => 'Confirmed by disaster monitoring desk.',
    ]);

    $response = $this->getJson('/api/disaster-dashboard');

    $response->assertOk();
    $events = $response->json('events');

    $eventIds = array_column($events, 'id');

    // Verified report is present
    expect($eventIds)->toContain($verifiedReport->id);

    // Unverified reports are NOT present
    expect($eventIds)->not->toContain($pendingReport->id);
    expect($eventIds)->not->toContain($rejectedReport->id);

    // Check verified report structure
    $matchedEvent = collect($events)->firstWhere('id', $verifiedReport->id);
    expect($matchedEvent['source'])->toBe('Community Report');
    expect($matchedEvent['is_community_report'])->toBeTrue();
    expect($matchedEvent['verification_status'])->toBe('verified');
    expect($matchedEvent['title'])->toBe('Verified Community Flood in Marikina');
});
