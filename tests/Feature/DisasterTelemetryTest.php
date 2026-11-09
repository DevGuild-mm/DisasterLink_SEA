<?php

use App\Services\Disaster\DisasterDashboardService;
use App\Services\Disaster\GdacsService;
use App\Services\Disaster\OpenMeteoService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
});

function fakeAllDisasterApis(): void
{
    Http::fake([
        'www.gdacs.org/*' => Http::response([
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [125.5, 9.8],
                    ],
                    'properties' => [
                        'eventid' => '1532820',
                        'episodeid' => '1',
                        'eventtype' => 'EQ',
                        'name' => 'Earthquake in Philippines',
                        'description' => 'Green M 5.6 Earthquake in Mindanao, Philippines',
                        'country' => 'Philippines',
                        'iso3' => 'PHL',
                        'alertlevel' => 'Orange',
                        'fromdate' => '2026-11-07T04:00:00Z',
                        'datemodified' => '2026-11-07T04:15:00Z',
                        'severitydata' => [
                            'severity' => 5.6,
                            'severitytext' => 'Magnitude 5.6M, Depth: 12.5km',
                            'severityunit' => 'M',
                        ],
                        'url' => [
                            'report' => 'https://www.gdacs.org/report.aspx?eventid=1532820&episodeid=1&eventtype=EQ',
                        ],
                    ],
                ],
                [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [-75.0, 40.0], // Outside SE Asia (USA)
                    ],
                    'properties' => [
                        'eventid' => '9999999',
                        'eventtype' => 'FL',
                        'name' => 'Flood in United States',
                        'country' => 'United States',
                        'iso3' => 'USA',
                        'alertlevel' => 'Green',
                        'fromdate' => '2026-11-07T01:00:00Z',
                    ],
                ],
            ],
        ], 200),

        'earthquake.usgs.gov/*' => Http::response([
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'id' => 'us6000sea1',
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [125.52, 9.81, 12.5], // Same incident as GDACS EQ in Philippines
                    ],
                    'properties' => [
                        'mag' => 5.6,
                        'place' => '24 km E of Surigao, Philippines',
                        'time' => strtotime('2026-11-07T04:02:00Z') * 1000,
                        'updated' => strtotime('2026-11-07T04:20:00Z') * 1000,
                        'url' => 'https://earthquake.usgs.gov/earthquakes/eventpage/us6000sea1',
                        'title' => 'M 5.6 - 24 km E of Surigao, Philippines',
                        'type' => 'earthquake',
                        'alert' => 'green',
                    ],
                ],
                [
                    'type' => 'Feature',
                    'id' => 'us6000sea2',
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [96.12, 5.21, 18.0],
                    ],
                    'properties' => [
                        'mag' => 4.8,
                        'place' => '45 km SSW of Banda Aceh, Indonesia',
                        'time' => strtotime('2026-11-07T05:10:00Z') * 1000,
                        'updated' => strtotime('2026-11-07T05:25:00Z') * 1000,
                        'url' => 'https://earthquake.usgs.gov/earthquakes/eventpage/us6000sea2',
                        'title' => 'M 4.8 - 45 km SSW of Banda Aceh, Indonesia',
                        'type' => 'earthquake',
                    ],
                ],
            ],
        ], 200),

        'eonet.gsfc.nasa.gov/*' => Http::response([
            'title' => 'EONET Events',
            'events' => [
                [
                    'id' => 'EONET_SEA_1',
                    'title' => 'Wildfire in Central Kalimantan, Indonesia',
                    'description' => null,
                    'link' => 'https://eonet.gsfc.nasa.gov/api/v3/events/EONET_SEA_1',
                    'closed' => null,
                    'categories' => [
                        ['id' => 'wildfires', 'title' => 'Wildfires'],
                    ],
                    'sources' => [
                        ['id' => 'InciWeb', 'url' => 'https://eonet.gsfc.nasa.gov/api/v3/events/EONET_SEA_1'],
                    ],
                    'geometry' => [
                        [
                            'magnitudeValue' => null,
                            'magnitudeUnit' => null,
                            'date' => '2026-11-07T03:00:00Z',
                            'type' => 'Point',
                            'coordinates' => [113.92, -1.68],
                        ],
                    ],
                ],
            ],
        ], 200),

        'api.open-meteo.com/*' => Http::response(
            array_map(fn ($city) => [
                'latitude' => $city['lat'],
                'longitude' => $city['lng'],
                'current' => [
                    'time' => '2026-11-07T12:00',
                    'temperature_2m' => 30.4,
                    'relative_humidity_2m' => 76,
                    'apparent_temperature' => 35.1,
                    'is_day' => 1,
                    'precipitation' => 0.2,
                    'weather_code' => 2,
                    'wind_speed_10m' => 14.5,
                    'wind_direction_10m' => 180,
                ],
                'daily' => [
                    'weather_code' => [2],
                    'temperature_2m_max' => [32.5],
                    'temperature_2m_min' => [25.0],
                    'precipitation_sum' => [2.4],
                ],
            ], OpenMeteoService::CITIES),
            200,
        ),
    ]);
}

test('normalizes GDACS, USGS, and NASA EONET events with consistent schema and deduplicates cross-agency incidents', function () {
    fakeAllDisasterApis();

    $dashboard = app(DisasterDashboardService::class)->getDashboardData(true);

    expect($dashboard['sources']['gdacs']['status'])->toBe('Live')
        ->and($dashboard['sources']['gdacs']['freshness_status'])->toBe('Fresh')
        ->and($dashboard['sources']['usgs']['status'])->toBe('Live')
        ->and($dashboard['sources']['eonet']['status'])->toBe('Live')
        ->and($dashboard['sources']['weather']['status'])->toBe('Live')
        ->and($dashboard['weather'])->toHaveCount(11);

    // 1 GDACS EQ + 2 USGS EQs (1 overlapping with GDACS) + 1 EONET Wildfire = 3 unique events
    expect($dashboard['events'])->toHaveCount(3);

    // Check consistent fields on every event
    foreach ($dashboard['events'] as $event) {
        expect($event)->toHaveKeys([
            'id',
            'disaster_type',
            'category',
            'title',
            'country',
            'location',
            'latitude',
            'longitude',
            'coordinates',
            'severity',
            'description',
            'event_date',
            'event_time',
            'source',
            'sources',
            'source_url',
            'source_urls',
            'last_updated_time',
        ]);
    }

    // Verify NASA EONET event does NOT invent severity_level when not supplied by provider
    $eonetEvent = collect($dashboard['events'])->firstWhere('source', 'NASA EONET');
    expect($eonetEvent)->not->toBeNull()
        ->and($eonetEvent['severity_level'])->toBeNull()
        ->and($eonetEvent['severity'])->toBeNull()
        ->and($eonetEvent['country'])->toBe('Indonesia')
        ->and($eonetEvent['disaster_type'])->toBe('Wildfire');

    // Verify deduplicated Philippines earthquake combined GDACS and USGS sources
    $phEvent = collect($dashboard['events'])->firstWhere('country', 'Philippines');
    expect($phEvent)->not->toBeNull()
        ->and($phEvent['sources'])->toContain('GDACS', 'USGS')
        ->and($phEvent['severity_level'])->toBe('Orange')
        ->and($phEvent['location'])->toBe('24 km E of Surigao, Philippines')
        ->and($phEvent['depth_km'])->toBe(12.5);
});

test('serves stale cached data when fresh TTL expires and upstream API fails', function () {
    Http::fake([
        'www.gdacs.org/*' => Http::sequence()
            ->push([
                'type' => 'FeatureCollection',
                'features' => [
                    [
                        'type' => 'Feature',
                        'geometry' => [
                            'type' => 'Point',
                            'coordinates' => [125.5, 9.8],
                        ],
                        'properties' => [
                            'eventid' => '1532820',
                            'episodeid' => '1',
                            'eventtype' => 'EQ',
                            'name' => 'Earthquake in Philippines',
                            'description' => 'Green M 5.6 Earthquake in Mindanao, Philippines',
                            'country' => 'Philippines',
                            'iso3' => 'PHL',
                            'alertlevel' => 'Orange',
                            'fromdate' => '2026-11-07T04:00:00Z',
                            'datemodified' => '2026-11-07T04:15:00Z',
                        ],
                    ],
                ],
            ], 200)
            ->push('Service Unavailable', 503),
    ]);

    $gdacsService = app(GdacsService::class);
    $initial = $gdacsService->getEvents(true);

    expect($initial['meta']['status'])->toBe('Live')
        ->and($initial['meta']['freshness_status'])->toBe('Fresh')
        ->and($initial['meta']['is_fresh'])->toBeTrue()
        ->and($initial['events'])->toHaveCount(1);

    // Expire fresh lock while keeping 24h stale backup cache intact
    Cache::forget('gdacs_fresh_lock_sea');

    $staleResult = $gdacsService->getEvents(false);

    expect($staleResult['meta']['status'])->toBe('Stale')
        ->and($staleResult['meta']['freshness_status'])->toBe('Stale')
        ->and($staleResult['meta']['is_stale'])->toBeTrue()
        ->and($staleResult['meta']['is_fresh'])->toBeFalse()
        ->and($staleResult['meta']['last_successful_fetch'])->toBe($initial['meta']['last_successful_fetch'])
        ->and($staleResult['events'])->toHaveCount(1);
});

test('reports Unavailable status and never fabricates statistics when all upstream APIs fail with cold cache', function () {
    Http::fake([
        'www.gdacs.org/*' => Http::response('Rate limit', 429),
        'earthquake.usgs.gov/*' => Http::response('Server error', 500),
        'eonet.gsfc.nasa.gov/*' => Http::response('Bad Gateway', 502),
        'api.open-meteo.com/*' => Http::response('Service Unavailable', 503),
    ]);

    $dashboard = app(DisasterDashboardService::class)->getDashboardData(true);

    expect($dashboard['events'])->toBeEmpty()
        ->and($dashboard['weather'])->toBeEmpty()
        ->and($dashboard['sources']['gdacs']['status'])->toBe('Unavailable')
        ->and($dashboard['sources']['gdacs']['freshness_status'])->toBe('Unavailable')
        ->and($dashboard['sources']['usgs']['status'])->toBe('Unavailable')
        ->and($dashboard['sources']['eonet']['status'])->toBe('Unavailable')
        ->and($dashboard['sources']['weather']['status'])->toBe('Unavailable')
        ->and($dashboard['summary']['total_active_alerts']['status'])->toBe('Unavailable')
        ->and($dashboard['summary']['earthquakes']['status'])->toBe('Unavailable')
        ->and($dashboard['summary']['tropical_cyclones']['status'])->toBe('Unavailable')
        ->and($dashboard['summary']['floods']['status'])->toBe('Unavailable')
        ->and($dashboard['summary']['wildfires']['status'])->toBe('Unavailable');
});

test('all web pages and JSON telemetry API endpoint return HTTP 200', function () {
    fakeAllDisasterApis();

    foreach (['/', '/overview', '/map', '/events', '/weather', '/freshness'] as $pageRoute) {
        $this->get($pageRoute)->assertOk();
    }

    $apiResponse = $this->getJson('/api/disaster-dashboard?refresh=1');
    $apiResponse->assertOk()
        ->assertJsonStructure([
            'generated_at',
            'summary' => [
                'total_active_alerts',
                'earthquakes',
                'tropical_cyclones',
                'floods',
                'wildfires',
                'volcanoes',
                'total_events',
            ],
            'events',
            'weather',
            'sources' => [
                'gdacs',
                'usgs',
                'eonet',
                'weather',
            ],
            'countries',
            'categories',
        ]);
});

test('individual disaster event detail page returns HTTP 200 with enriched timeline for valid event', function () {
    fakeAllDisasterApis();

    $dashboardService = app(DisasterDashboardService::class);
    $dashboardData = $dashboardService->getDashboardData(true);
    expect($dashboardData['events'])->not->toBeEmpty();

    $firstEvent = $dashboardData['events'][0];
    $eventId = $firstEvent['id'];

    $retrieved = $dashboardService->getEventById($eventId);
    expect($retrieved)->not->toBeNull()
        ->and($retrieved['id'])->toBe($eventId)
        ->and($retrieved)->toHaveKey('timeline')
        ->and($retrieved['timeline'])->toBeArray();

    // Direct HTTP request to /events/{id}
    $response = $this->get("/events/{$eventId}");
    $response->assertOk();
});

test('individual disaster event detail page handles non-existent event IDs gracefully', function () {
    fakeAllDisasterApis();

    $response = $this->get('/events/non_existent_disaster_id_9999');
    $response->assertOk();
});

test('country monitoring dashboard displays aggregated ASEAN data and analytics on /countries', function () {
    fakeAllDisasterApis();

    $response = $this->get('/countries');
    $response->assertOk();

    $apiResponse = $this->getJson('/api/countries');
    $apiResponse->assertOk()
        ->assertJsonStructure([
            'generated_at',
            'selected_country',
            'is_valid_country',
            'country_meta',
            'all_countries',
            'summary' => [
                'total_events',
                'high_alerts_count',
                'red_alerts_count',
                'orange_alerts_count',
                'green_alerts_count',
                'unclassified_count',
                'categories_count',
            ],
            'events',
            'weather',
            'analytics' => [
                'by_category',
                'by_country',
                'historical_trends',
            ],
            'sources',
        ]);
});

test('country monitoring dashboard supports all 11 ASEAN individual country routes', function () {
    fakeAllDisasterApis();

    $aseanCountries = [
        'brunei',
        'cambodia',
        'indonesia',
        'laos',
        'malaysia',
        'myanmar',
        'philippines',
        'singapore',
        'thailand',
        'timor-leste',
        'vietnam',
    ];

    foreach ($aseanCountries as $countrySlug) {
        $response = $this->get("/countries/{$countrySlug}");
        $response->assertOk();
    }
});

test('country monitoring dashboard gracefully handles invalid country routes without crashing', function () {
    fakeAllDisasterApis();

    $response = $this->get('/countries/invalid_country_atlantis');
    $response->assertOk();

    $apiResponse = $this->getJson('/api/countries/invalid_country_atlantis');
    $apiResponse->assertOk()
        ->assertJson([
            'is_valid_country' => false,
            'selected_country' => 'All',
        ]);
});

test('country monitoring dashboard caches country-level results with specific keys', function () {
    fakeAllDisasterApis();

    $service = app(DisasterDashboardService::class);

    // Initial fetch for Myanmar
    $myanmarData = $service->getCountryDashboardData('myanmar');
    expect($myanmarData['selected_country'])->toBe('Myanmar')
        ->and(Cache::has('disaster_country_dashboard_myanmar'))->toBeTrue();

    // Initial fetch for Philippines
    $philippinesData = $service->getCountryDashboardData('philippines');
    expect($philippinesData['selected_country'])->toBe('Philippines')
        ->and(Cache::has('disaster_country_dashboard_philippines'))->toBeTrue();
});
