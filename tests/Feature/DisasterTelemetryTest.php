<?php

use App\Models\Country;
use App\Models\DataSource;
use App\Models\DisasterEvent;
use App\Models\DisasterReport;
use App\Models\User;
use App\Services\Disaster\Dashboard\DashboardCache;
use App\Services\Disaster\DisasterDashboardService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['modules.countries' => true]);
    Cache::flush();
    Http::preventStrayRequests();
});

function seedTelemetry(): void
{
    seedDashboardData([
        ['iso3' => 'PHL', 'external_id' => 'EQ:1532820', 'event_type' => 'earthquake', 'title' => 'Earthquake in Philippines', 'alert_level' => 'Orange', 'magnitude' => 5.6, 'depth_km' => 12.5, 'latitude' => 9.8, 'longitude' => 125.5, 'location_text' => 'Mindanao', 'source_url' => 'https://www.gdacs.org/report.aspx?eventid=1532820', 'source_meta' => ['severity_text' => 'Magnitude 5.6M, Depth: 12.5km']],
        ['iso3' => 'PHL', 'source' => 'usgs', 'external_id' => 'us6000sea1', 'event_type' => 'earthquake', 'title' => 'M 5.6 - 24 km E of Surigao, Philippines', 'alert_level' => 'Orange', 'alert_level_derived' => true, 'magnitude' => 5.6, 'depth_km' => 12.5, 'latitude' => 9.81, 'longitude' => 125.52, 'location_text' => '24 km E of Surigao, Philippines', 'source_url' => 'https://earthquake.usgs.gov/earthquakes/eventpage/us6000sea1'],
        ['iso3' => 'IDN', 'source' => 'usgs', 'external_id' => 'us6000sea2', 'event_type' => 'earthquake', 'title' => 'M 4.8 - Banda Aceh', 'alert_level' => 'Green', 'magnitude' => 4.8, 'latitude' => 5.21, 'longitude' => 96.12],
        ['iso3' => 'IDN', 'external_id' => 'WF:77', 'event_type' => 'wildfire', 'title' => 'Wildfire in Central Kalimantan', 'alert_level' => null, 'latitude' => -1.68, 'longitude' => 113.92],
    ]);

    // The USGS quake is the same incident as the GDACS one: the linker points it at the canonical row.
    $canonical = DisasterEvent::where('external_id', 'EQ:1532820')->first();
    DisasterEvent::where('external_id', 'us6000sea1')->update(['canonical_event_id' => $canonical->id]);
    markSourcesSynced();
}

test('builds one consistent event shape from stored GDACS and USGS events and merges linked duplicates', function () {
    seedTelemetry();

    $dashboard = app(DisasterDashboardService::class)->getDashboardData();

    expect($dashboard['sources']['gdacs']['status'])->toBe('Live')
        ->and($dashboard['sources']['gdacs']['freshness_status'])->toBe('Fresh')
        ->and($dashboard['sources']['usgs']['status'])->toBe('Live')
        ->and($dashboard['sources']['weather']['status'])->toBe('Live')
        ->and($dashboard['sources'])->not->toHaveKey('eonet')
        ->and($dashboard['weather'])->toHaveCount(11)
        ->and($dashboard['events'])->toHaveCount(3);

    foreach ($dashboard['events'] as $event) {
        expect($event)->toHaveKeys([
            'id', 'disaster_type', 'category', 'title', 'country', 'location', 'latitude', 'longitude', 'coordinates',
            'severity', 'description', 'event_date', 'event_time', 'source', 'sources', 'source_url', 'source_urls', 'last_updated_time',
        ]);
    }

    $wildfire = collect($dashboard['events'])->firstWhere('disaster_type', 'Wildfire');
    expect($wildfire['severity_level'])->toBeNull()
        ->and($wildfire['severity'])->toBeNull()
        ->and($wildfire['country'])->toBe('Indonesia');

    $philippines = collect($dashboard['events'])->firstWhere('country', 'Philippines');
    expect($philippines['sources'])->toContain('GDACS', 'USGS')
        ->and($philippines['severity_level'])->toBe('Orange')
        ->and($philippines['depth_km'])->toBe(12.5)
        ->and($philippines['source_urls'])->toHaveCount(2);
});

test('reports Unavailable and never invents statistics when nothing has been synced', function () {
    seedDashboardData(withWeather: false);

    $dashboard = app(DisasterDashboardService::class)->getDashboardData();

    expect($dashboard['events'])->toBeEmpty()
        ->and($dashboard['weather'])->toBeEmpty()
        ->and($dashboard['data_as_of'])->toBeNull()
        ->and($dashboard['sources']['gdacs']['status'])->toBe('Unavailable')
        ->and($dashboard['sources']['usgs']['status'])->toBe('Unavailable')
        ->and($dashboard['sources']['weather']['status'])->toBe('Unavailable')
        ->and($dashboard['summary']['total_active_alerts']['status'])->toBe('Unavailable')
        ->and($dashboard['summary']['earthquakes']['status'])->toBe('Unavailable')
        ->and($dashboard['summary']['total_events'])->toBe(0);
});

test('a source whose last success is old is shown as Stale, never Live', function () {
    seedTelemetry();
    DataSource::where('key', 'gdacs')->update(['last_success_at' => now()->subHours(3), 'last_status' => 'failed', 'last_error' => 'GDACS returned HTTP 503.']);

    $sources = app(DisasterDashboardService::class)->getDashboardData()['sources'];

    expect($sources['gdacs']['status'])->toBe('Stale')
        ->and($sources['gdacs']['is_stale'])->toBeTrue()
        ->and($sources['gdacs']['error'])->toBe('GDACS returned HTTP 503.')
        ->and($sources['usgs']['status'])->toBe('Live');
});

test('all web pages and the JSON telemetry API return HTTP 200 without contacting any provider', function () {
    seedTelemetry();

    foreach (['/', '/overview', '/map', '/events', '/weather', '/preparedness'] as $pageRoute) {
        $this->get($pageRoute)->assertOk();
    }

    $this->getJson('/api/disaster-dashboard?refresh=1')
        ->assertOk()
        ->assertJsonStructure([
            'generated_at',
            'data_as_of',
            'summary' => ['total_active_alerts', 'earthquakes', 'tropical_cyclones', 'floods', 'wildfires', 'volcanoes', 'total_events'],
            'events',
            'weather',
            'sources' => ['gdacs', 'usgs', 'weather'],
            'countries',
            'categories',
        ]);

    Http::assertNothingSent();
});

test('an event detail page loads a stored event with its timeline', function () {
    seedTelemetry();

    $service = app(DisasterDashboardService::class);
    $eventId = $service->getDashboardData()['events'][0]['id'];

    $retrieved = $service->getEventById($eventId);
    expect($retrieved)->not->toBeNull()
        ->and($retrieved['id'])->toBe($eventId)
        ->and($retrieved['timeline'])->toBeArray();

    $this->get("/events/{$eventId}")->assertOk();
});

test('an event older than the live window is still reachable by its id', function () {
    seedDashboardData([['iso3' => 'PHL', 'external_id' => 'EQ:5', 'event_type' => 'earthquake', 'title' => 'Old quake', 'occurred_at' => now()->subDays(30)]]);

    expect(app(DisasterDashboardService::class)->getDashboardData()['events'])->toBeEmpty()
        ->and(app(DisasterDashboardService::class)->getEventById('gdacs_EQ:5')['title'])->toBe('Old quake');
});

test('an unknown event id is a 404, not an empty page', function (string $id) {
    seedTelemetry();

    $this->get('/events/'.$id)->assertNotFound();
    expect(app(DisasterDashboardService::class)->getEventById($id))->toBeNull();
})->with([
    'never existed' => ['non_existent_disaster_id_9999'],
    'right source, unknown id' => ['gdacs_EQ:424242'],
    'old pre-Phase-5 id format' => ['gdacs_eq_1532820'],
    'bare number' => ['2001'],
    'unknown source prefix' => ['nasa_EONET_1'],
]);

test('every event id the API hands to a link opens its page, with the colon raw or encoded', function () {
    seedTelemetry();

    $events = $this->getJson('/api/disaster-dashboard')->assertOk()->json('events');
    expect($events)->not->toBeEmpty();

    foreach ($events as $event) {
        // The Vue links build "/events/" + id with no encoding; Inertia and browsers may also send %3A.
        $this->get('/events/'.$event['id'])->assertOk()->assertInertia(fn ($page) => $page->where('event.id', $event['id']));
        $this->get('/events/'.rawurlencode($event['id']))->assertOk()->assertInertia(fn ($page) => $page->where('event.id', $event['id']));
    }

    expect(collect($events)->pluck('id'))->toContain('gdacs_EQ:1532820', 'gdacs_WF:77', 'usgs_us6000sea2');
});

test('only verified community reports have a public event page', function () {
    seedTelemetry();
    $user = User::factory()->create();

    $make = fn (string $status) => DisasterReport::create([
        'user_id' => $user->id, 'disaster_type' => 'Flood', 'title' => "Report {$status}", 'description' => 'Water is rising.',
        'country' => 'Philippines', 'location' => 'Marikina', 'incident_time' => now()->subHour(), 'status' => $status,
    ]);

    $verified = $make(DisasterReport::STATUS_VERIFIED);
    $pending = $make(DisasterReport::STATUS_PENDING);
    $rejected = $make(DisasterReport::STATUS_REJECTED);

    $this->get("/events/{$verified->id}")->assertOk()->assertInertia(fn ($page) => $page->where('event.is_community_report', true));
    $this->get("/events/{$pending->id}")->assertNotFound();
    $this->get("/events/{$rejected->id}")->assertNotFound();
});

test('the weather payload sends null, not zero, for values the database does not store', function () {
    seedTelemetry();

    $weather = $this->getJson('/api/disaster-dashboard')->json('weather.0');

    expect($weather['forecast_precipitation_sum_mm'])->toBeNull()
        ->and($weather['apparent_temperature_c'])->toBeNull()
        ->and($weather['precipitation_mm'])->toEqual(0);
});

test('country monitoring pages and API return the aggregated ASEAN structure', function () {
    seedTelemetry();

    $this->get('/countries')->assertOk();

    $this->getJson('/api/countries')
        ->assertOk()
        ->assertJsonStructure([
            'generated_at', 'selected_country', 'is_valid_country', 'country_meta', 'all_countries',
            'summary' => ['total_events', 'high_alerts_count', 'red_alerts_count', 'orange_alerts_count', 'green_alerts_count', 'unclassified_count', 'categories_count'],
            'events', 'weather',
            'analytics' => ['by_category', 'by_country', 'historical_trends'],
            'sources',
        ]);
});

test('all 11 ASEAN country routes render', function () {
    seedTelemetry();

    foreach (['brunei', 'cambodia', 'indonesia', 'laos', 'malaysia', 'myanmar', 'philippines', 'singapore', 'thailand', 'timor-leste', 'vietnam'] as $slug) {
        $this->get("/countries/{$slug}")->assertOk();
    }
});

test('an invalid country falls back to the All view without crashing', function () {
    seedTelemetry();

    $this->get('/countries/invalid_country_atlantis')->assertOk();
    $this->getJson('/api/countries/invalid_country_atlantis')
        ->assertOk()
        ->assertJson(['is_valid_country' => false, 'selected_country' => 'All']);
});

test('a country view follows a sync at once, with no separate country cache', function () {
    seedTelemetry();
    $service = app(DisasterDashboardService::class);

    expect($service->getCountryDashboardData('myanmar')['summary']['total_events'])->toBe(0);

    seedDashboardData(withWeather: false);
    DisasterEvent::factory()->create([
        'external_id' => 'FL:9', 'event_type' => 'flood', 'title' => 'Flood in Yangon', 'occurred_at' => now()->subHour(),
        'country_id' => Country::where('iso3', 'MMR')->value('id'), 'country_status' => 'resolved',
    ]);
    app(DashboardCache::class)->invalidateAfterSync();

    expect($service->getCountryDashboardData('myanmar')['summary']['total_events'])->toBe(1);
});
