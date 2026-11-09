<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['modules.countries' => true]);
    Cache::flush();
});

function fakeAseanTelemetry(): void
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
                        'eventid' => '1001',
                        'episodeid' => '1',
                        'eventtype' => 'EQ',
                        'name' => 'Earthquake in Mindanao',
                        'description' => 'Moderate earthquake in Mindanao, Philippines',
                        'country' => 'Philippines',
                        'iso3' => 'PHL',
                        'alertlevel' => 'Orange',
                        'fromdate' => Carbon::now()->subHours(2)->toIso8601String(),
                        'severitydata' => [
                            'severity' => 5.5,
                            'severitytext' => 'Magnitude 5.5M',
                            'severityunit' => 'M',
                        ],
                        'url' => [
                            'report' => 'https://www.gdacs.org/report.aspx?eventid=1001',
                        ],
                    ],
                ],
                [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [106.8, -6.2],
                    ],
                    'properties' => [
                        'eventid' => '1002',
                        'episodeid' => '1',
                        'eventtype' => 'FL',
                        'name' => 'Flood in Jakarta',
                        'description' => 'Inundation flood in Jakarta, Indonesia',
                        'country' => 'Indonesia',
                        'iso3' => 'IDN',
                        'alertlevel' => 'Red',
                        'fromdate' => Carbon::now()->subHours(4)->toIso8601String(),
                        'severitydata' => [
                            'severity' => 2.0,
                            'severitytext' => 'Red Flood Alert',
                            'severityunit' => 'Alert',
                        ],
                        'url' => [
                            'report' => 'https://www.gdacs.org/report.aspx?eventid=1002',
                        ],
                    ],
                ],
            ],
        ]),
        'earthquake.usgs.gov/*' => Http::response(['type' => 'FeatureCollection', 'features' => []]),
        'eonet.gsfc.nasa.gov/*' => Http::response(['events' => []]),
        'api.open-meteo.com/*' => Http::response(array_map(fn () => [
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
        ], range(1, 11))),
    ]);
}

test('1. default country monitoring page renders All Southeast Asia', function () {
    fakeAseanTelemetry();

    $response = $this->get('/countries');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('CountryMonitoringPage')
        ->where('selectedCountry', 'All')
        ->where('countryData.selected_country', 'All')
        ->where('countryData.is_valid_country', true)
        ->has('countryData.all_countries')
    );
});

test('2. all 11 ASEAN member country routes render successfully with correct metadata', function (string $countryKey, string $slug, string $expectedCode) {
    fakeAseanTelemetry();

    $response = $this->get("/countries/{$slug}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('CountryMonitoringPage')
        ->where('selectedCountry', $countryKey)
        ->where('countryData.selected_country', $countryKey)
        ->where('countryData.country_meta.code', $expectedCode)
        ->where('countryData.is_valid_country', true)
        ->has('countryData.weather')
        ->has('countryData.analytics')
    );
})->with([
    ['Brunei', 'brunei', 'BN'],
    ['Cambodia', 'cambodia', 'KH'],
    ['Indonesia', 'indonesia', 'ID'],
    ['Laos', 'laos', 'LA'],
    ['Malaysia', 'malaysia', 'MY'],
    ['Myanmar', 'myanmar', 'MM'],
    ['Philippines', 'philippines', 'PH'],
    ['Singapore', 'singapore', 'SG'],
    ['Thailand', 'thailand', 'TH'],
    ['Timor-Leste', 'timor-leste', 'TL'],
    ['Vietnam', 'vietnam', 'VN'],
]);

test('3. country routes are case-insensitive and handle slug variations', function () {
    fakeAseanTelemetry();

    // Uppercase
    $r1 = $this->get('/countries/PHILIPPINES');
    $r1->assertOk();
    $r1->assertInertia(fn ($page) => $page->where('selectedCountry', 'Philippines'));

    // Hyphenated Timor-Leste
    $r2 = $this->get('/countries/timor-leste');
    $r2->assertOk();
    $r2->assertInertia(fn ($page) => $page->where('selectedCountry', 'Timor-Leste'));

    // Lao PDR alternate name
    $r3 = $this->get('/countries/lao-pdr');
    $r3->assertOk();
    $r3->assertInertia(fn ($page) => $page->where('selectedCountry', 'Laos'));
});

test('4. invalid country parameter falls back gracefully to All view without 500 error', function () {
    fakeAseanTelemetry();

    $response = $this->get('/countries/atlantis');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('CountryMonitoringPage')
        ->where('countryData.selected_country', 'All')
        ->where('countryData.is_valid_country', false)
        ->where('countryData.requested_country', 'atlantis')
    );
});

test('5. country monitoring API returns valid JSON telemetry for all 11 nations', function () {
    fakeAseanTelemetry();

    // Philippines JSON API
    $respPhil = $this->getJson('/api/countries/philippines');
    $respPhil->assertOk();
    $respPhil->assertJsonPath('selected_country', 'Philippines');
    $respPhil->assertJsonPath('country_meta.code', 'PH');
    $respPhil->assertJsonPath('is_valid_country', true);

    // Indonesia JSON API
    $respIndo = $this->getJson('/api/countries/indonesia');
    $respIndo->assertOk();
    $respIndo->assertJsonPath('selected_country', 'Indonesia');
    $respIndo->assertJsonPath('country_meta.code', 'ID');
    $respIndo->assertJsonPath('is_valid_country', true);

    // All ASEAN JSON API
    $respAll = $this->getJson('/api/countries/all');
    $respAll->assertOk();
    $respAll->assertJsonPath('selected_country', 'All');
    $respAll->assertJsonPath('country_meta.code', 'ALL');
});

test('6. country events and statistics are strictly filtered by country', function () {
    fakeAseanTelemetry();

    // Philippines should show 1 event (the Mindanao earthquake)
    $respPhil = $this->getJson('/api/countries/philippines');
    $respPhil->assertOk();
    $philEvents = $respPhil->json('events');
    expect($philEvents)->toHaveCount(1);
    expect($philEvents[0]['country'])->toBe('Philippines');
    expect($respPhil->json('summary.total_events'))->toBe(1);
    expect($respPhil->json('summary.orange_alerts_count'))->toBe(1);

    // Indonesia should show 1 event (the Jakarta flood)
    $respIndo = $this->getJson('/api/countries/indonesia');
    $respIndo->assertOk();
    $indoEvents = $respIndo->json('events');
    expect($indoEvents)->toHaveCount(1);
    expect($indoEvents[0]['country'])->toBe('Indonesia');
    expect($respIndo->json('summary.total_events'))->toBe(1);
    expect($respIndo->json('summary.red_alerts_count'))->toBe(1);

    // Singapore has 0 events and correctly reports 0
    $respSgp = $this->getJson('/api/countries/singapore');
    $respSgp->assertOk();
    expect($respSgp->json('events'))->toBeEmpty();
    expect($respSgp->json('summary.total_events'))->toBe(0);
});

test('7. capital weather is accurately attributed to the country', function () {
    fakeAseanTelemetry();

    $respPhil = $this->getJson('/api/countries/philippines');
    $respPhil->assertOk();
    $weather = $respPhil->json('weather');
    expect($weather)->toHaveCount(1);
    expect($weather[0]['city'])->toBe('Manila');
    expect($weather[0]['country'])->toBe('Philippines');

    $respThai = $this->getJson('/api/countries/thailand');
    $respThai->assertOk();
    $thaiWeather = $respThai->json('weather');
    expect($thaiWeather)->toHaveCount(1);
    expect($thaiWeather[0]['city'])->toBe('Bangkok');
    expect($thaiWeather[0]['country'])->toBe('Thailand');
});
