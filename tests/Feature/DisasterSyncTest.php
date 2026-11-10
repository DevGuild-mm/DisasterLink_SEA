<?php

use App\Models\Country;
use App\Models\CountryBoundary;
use App\Models\DataSource;
use App\Models\DisasterEvent;
use App\Models\SyncRun;
use App\Services\Disaster\Sync\DisasterSyncService;
use App\Services\Disaster\Sync\SafeUrl;
use App\Services\Disaster\Sync\SourceFetchException;
use App\Services\Disaster\Sync\UsgsClient;
use Carbon\CarbonInterval;
use Database\Seeders\DataSourceSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

const GDACS_URL = 'https://www.gdacs.org/gdacsapi/api/events/geteventlist/SEARCH*';
const USGS_URL = 'https://earthquake.usgs.gov/earthquakes/feed/v1.0/summary/all_day.geojson*';

beforeEach(function () {
    $this->seed(DataSourceSeeder::class);
    Sleep::fake();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function gdacsFeature(array $overrides = []): array
{
    $props = array_replace_recursive([
        'eventtype' => 'TC',
        'eventid' => 1001346,
        'episodeid' => 10,
        'name' => 'Tropical Cyclone <b>MURASU-26</b>',
        'description' => 'Tropical Cyclone MURASU-26 &amp; friends',
        'alertlevel' => 'Orange',
        'iscurrent' => 'true',
        'country' => 'Philippines',
        'fromdate' => '2026-11-08T00:00:00',
        'todate' => '2026-11-10T06:00:00',
        'datemodified' => '2026-11-09T06:01:20',
        'iso3' => 'PHL',
        'url' => ['report' => 'https://www.gdacs.org/report.aspx?eventid=1001346&episodeid=10&eventtype=TC'],
        'affectedcountries' => [['iso2' => 'PH', 'iso3' => 'PHL', 'countryname' => 'Philippines']],
        'severitydata' => ['severity' => 157.4, 'severitytext' => 'Typhoon (157 km/h)', 'severityunit' => 'km/h'],
    ], $overrides['properties'] ?? []);

    return [
        'type' => 'Feature',
        'geometry' => $overrides['geometry'] ?? ['type' => 'Point', 'coordinates' => [125.1, 12.3]],
        'properties' => $props,
    ];
}

/**
 * @param  array<int, array<string, mixed>>  $features
 * @return array<string, mixed>
 */
function gdacsResponse(array $features): array
{
    return ['type' => 'FeatureCollection', 'features' => $features];
}

/**
 * A feed the GDACS fake serves: Orange/Red as one page, Green as a list of pages.
 *
 * @param  array<int, array<string, mixed>>  $orangeRed
 * @param  array<int, array<int, array<string, mixed>>>  $greenPages
 */
function gdacsFeed(array $orangeRed = [], array $greenPages = []): ArrayObject
{
    return new ArrayObject(['orange_red' => $orangeRed, 'green' => $greenPages]);
}

function fakeGdacs(ArrayObject $feed): void
{
    Http::fake([GDACS_URL => function (Request $request) use ($feed) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        $features = $query['alertlevel'] === 'green'
            ? ($feed['green'][(int) $query['pagenumber'] - 1] ?? [])
            : ((int) $query['pagenumber'] === 1 ? $feed['orange_red'] : []);

        return $features === [] ? Http::response('', 204) : Http::response(gdacsResponse($features));
    }]);
}

/**
 * @return array<int, array<string, mixed>>
 */
function gdacsFeatures(int $count, int $firstId = 1, string $level = 'Green'): array
{
    return array_map(
        fn (int $id) => gdacsFeature(['properties' => ['eventid' => $id, 'alertlevel' => $level]]),
        range($firstId, $firstId + $count - 1),
    );
}

/**
 * @param  array<string, mixed>  $props
 * @return array<string, mixed>
 */
function usgsFeature(string $id = 'us7000abcd', array $props = [], ?array $coordinates = null): array
{
    return [
        'type' => 'Feature',
        'id' => $id,
        'properties' => array_merge([
            'mag' => 5.6,
            'place' => '45 km SSW of Padang, Indonesia',
            'time' => 1794257180000,
            'updated' => 1794257277000,
            'url' => 'https://earthquake.usgs.gov/earthquakes/eventpage/'.$id,
            'status' => 'reviewed',
            'tsunami' => 0,
            'type' => 'earthquake',
            'title' => 'M 5.6 - 45 km SSW of Padang, Indonesia',
        ], $props),
        'geometry' => ['type' => 'Point', 'coordinates' => $coordinates ?? [100.1, -1.2, 22.5]],
    ];
}

/**
 * @param  array<int, array<string, mixed>>  $features
 * @return array<string, mixed>
 */
function usgsResponse(array $features): array
{
    return ['type' => 'FeatureCollection', 'features' => $features];
}

test('gdacs sync stores regional events with the HLD identity and no guessed country', function () {
    Http::fake([GDACS_URL => Http::response(gdacsResponse([
        gdacsFeature(),
        gdacsFeature(['properties' => ['eventtype' => 'DR', 'eventid' => 5, 'iso3' => 'ALB', 'affectedcountries' => [['iso3' => 'ALB', 'countryname' => 'Albania']]], 'geometry' => ['type' => 'Point', 'coordinates' => [20.0, 41.0]]]),
    ]))]);

    $run = app(DisasterSyncService::class)->sync('gdacs');

    expect($run->status)->toBe('success')
        ->and($run->fetched_count)->toBe(2)
        ->and($run->created_count)->toBe(1)
        ->and($run->skipped_count)->toBe(1)
        ->and(DisasterEvent::count())->toBe(1);

    $event = DisasterEvent::first();

    expect($event->source)->toBe('gdacs')
        ->and($event->external_id)->toBe('TC:1001346')
        ->and($event->episode_id)->toBe('10')
        ->and($event->event_type)->toBe('cyclone')
        ->and($event->alert_level)->toBe('Orange')
        ->and($event->alert_level_derived)->toBeFalse()
        ->and($event->title)->toBe('Tropical Cyclone MURASU-26')
        ->and($event->description)->toBe('Tropical Cyclone MURASU-26 & friends')
        ->and($event->source_url)->toStartWith('https://www.gdacs.org/')
        ->and($event->source_meta['iso3'])->toBe('PHL')
        ->and($event->country_id)->toBeNull()
        ->and($event->country_status)->toBe('unresolved')
        ->and($event->alert_status)->toBe('not_eligible')
        ->and($event->occurred_at->toDateTimeString())->toBe('2026-11-08 00:00:00')
        ->and($event->ended_at)->toBeNull();
});

test('gdacs request asks for the six hazard types over the last 7 days', function () {
    Http::fake([GDACS_URL => Http::response(gdacsResponse([]))]);

    app(DisasterSyncService::class)->sync('gdacs');

    Http::assertSent(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return str_starts_with($request->url(), 'https://www.gdacs.org/')
            && $query['eventlist'] === 'EQ;TC;FL;VO;WF;DR'
            && $query['fromdate'] === now()->subDays(7)->toDateString()
            && $query['todate'] === now()->toDateString();
    });
});

test('a gdacs event named only through affected countries is kept', function () {
    Http::fake([GDACS_URL => Http::response(gdacsResponse([
        gdacsFeature(['properties' => ['eventid' => 7, 'iso3' => 'CHN', 'affectedcountries' => [['iso3' => 'CHN', 'countryname' => 'China'], ['iso3' => 'VNM', 'countryname' => 'Viet Nam']]], 'geometry' => ['type' => 'Point', 'coordinates' => [60.0, 40.0]]]),
    ]))]);

    app(DisasterSyncService::class)->sync('gdacs');

    expect(DisasterEvent::count())->toBe(1);
});

test('a finished gdacs event gets an end time', function () {
    Http::fake([GDACS_URL => Http::response(gdacsResponse([gdacsFeature(['properties' => ['iscurrent' => 'false']])]))]);

    app(DisasterSyncService::class)->sync('gdacs');

    expect(DisasterEvent::first()->ended_at->toDateTimeString())->toBe('2026-11-10 06:00:00');
});

test('running a sync twice creates no duplicates and keeps first_seen_at', function () {
    Http::fake([GDACS_URL => Http::response(gdacsResponse([gdacsFeature()]))]);

    $this->travelTo(now()->subHour());
    app(DisasterSyncService::class)->sync('gdacs');
    $firstSeen = DisasterEvent::first()->first_seen_at;

    $this->travelBack();
    $run = app(DisasterSyncService::class)->sync('gdacs');

    $event = DisasterEvent::first();

    expect(DisasterEvent::count())->toBe(1)
        ->and($run->created_count)->toBe(0)
        ->and($run->updated_count)->toBe(0)
        ->and($event->first_seen_at->equalTo($firstSeen))->toBeTrue()
        ->and($event->last_seen_at->isAfter($firstSeen))->toBeTrue();
});

test('a later sync updates the alert level in place without touching country or alert fields', function () {
    $feed = gdacsFeed(greenPages: [[gdacsFeature(['properties' => ['alertlevel' => 'Green']])]]);
    fakeGdacs($feed);

    app(DisasterSyncService::class)->sync('gdacs');
    DisasterEvent::first()->update(['alert_status' => 'pending', 'country_status' => 'resolved', 'country_method' => 'source_code']);

    $feed['orange_red'] = [gdacsFeature(['properties' => ['alertlevel' => 'Orange', 'episodeid' => 11]])];
    $feed['green'] = [];
    app(DisasterSyncService::class)->sync('gdacs');

    $event = DisasterEvent::first();

    expect(DisasterEvent::count())->toBe(1)
        ->and($event->alert_level)->toBe('Orange')
        ->and($event->episode_id)->toBe('11')
        ->and($event->alert_status)->toBe('pending')
        ->and($event->country_status)->toBe('resolved')
        ->and($event->country_method)->toBe('source_code');
});

test('features with unusable coordinates, an unknown type or no start time are skipped and counted', function () {
    Http::fake([GDACS_URL => Http::response(gdacsResponse([
        gdacsFeature(['properties' => ['eventid' => 1, 'iso3' => 'CHN', 'affectedcountries' => null], 'geometry' => ['type' => 'Point', 'coordinates' => [500, 200]]]),
        gdacsFeature(['properties' => ['eventtype' => 'XX', 'eventid' => 2]]),
        gdacsFeature(['properties' => ['eventid' => 3, 'fromdate' => 'not a date']]),
        gdacsFeature(['properties' => ['eventid' => 4]]),
    ]))]);

    $run = app(DisasterSyncService::class)->sync('gdacs');

    expect($run->skipped_count)->toBe(3)
        ->and(DisasterEvent::pluck('external_id')->all())->toBe(['TC:4']);
});

test('a gdacs feature without an id gets a deterministic synthetic id marked in source_meta', function () {
    $feature = gdacsFeature(['properties' => ['eventid' => '']]);
    Http::fake([GDACS_URL => Http::response(gdacsResponse([$feature]))]);

    app(DisasterSyncService::class)->sync('gdacs');
    app(DisasterSyncService::class)->sync('gdacs');

    $event = DisasterEvent::first();

    expect(DisasterEvent::count())->toBe(1)
        ->and($event->external_id)->toStartWith('syn:')
        ->and($event->source_meta['synthetic_id'])->toBeTrue();
});

test('usgs sync stores regional earthquakes and never guesses a country from the place text', function () {
    Http::fake([USGS_URL => Http::response(usgsResponse([
        usgsFeature('us7000abcd'),
        usgsFeature('nc75464537', ['place' => '5 km NW of The Geysers, CA'], [-122.79, 38.81, 3.0]),
        usgsFeature('us7000quarry', ['type' => 'quarry blast']),
    ]))]);

    $run = app(DisasterSyncService::class)->sync('usgs');

    expect($run->fetched_count)->toBe(3)
        ->and($run->created_count)->toBe(1)
        ->and($run->skipped_count)->toBe(2);

    $event = DisasterEvent::first();

    expect($event->source)->toBe('usgs')
        ->and($event->external_id)->toBe('us7000abcd')
        ->and($event->event_type)->toBe('earthquake')
        ->and((float) $event->magnitude)->toBe(5.6)
        ->and((float) $event->depth_km)->toBe(22.5)
        ->and($event->alert_level)->toBe('Orange')
        ->and($event->alert_level_derived)->toBeTrue()
        ->and($event->location_text)->toBe('45 km SSW of Padang, Indonesia')
        ->and($event->country_id)->toBeNull()
        ->and($event->country_status)->toBe('unresolved')
        ->and($event->country_method)->toBeNull()
        ->and($event->source_url)->toBe('https://earthquake.usgs.gov/earthquakes/eventpage/us7000abcd');
});

test('usgs derives display alert levels from magnitude', function (float $magnitude, string $level) {
    Http::fake([USGS_URL => Http::response(usgsResponse([usgsFeature('us1', ['mag' => $magnitude])]))]);

    app(DisasterSyncService::class)->sync('usgs');

    expect(DisasterEvent::first()->alert_level)->toBe($level);
})->with([[4.9, 'Green'], [5.0, 'Orange'], [6.4, 'Orange'], [6.5, 'Red']]);

test('usgs magnitude revision updates the same row', function () {
    Http::fake([USGS_URL => Http::sequence()
        ->push(usgsResponse([usgsFeature('us1', ['mag' => 4.9])]))
        ->push(usgsResponse([usgsFeature('us1', ['mag' => 5.0])])),
    ]);

    app(DisasterSyncService::class)->sync('usgs');
    app(DisasterSyncService::class)->sync('usgs');

    expect(DisasterEvent::count())->toBe(1)
        ->and((float) DisasterEvent::first()->magnitude)->toBe(5.0)
        ->and(DisasterEvent::first()->alert_level)->toBe('Orange');
});

test('a usgs feature without an id gets a synthetic id', function () {
    $feature = usgsFeature('x');
    unset($feature['id']);
    Http::fake([USGS_URL => Http::response(usgsResponse([$feature]))]);

    app(DisasterSyncService::class)->sync('usgs');

    expect(DisasterEvent::first()->external_id)->toStartWith('syn:');
});

test('a failed request records the failure and leaves stored events and last_success_at untouched', function () {
    DisasterEvent::factory()->create(['source' => 'gdacs', 'external_id' => 'TC:1', 'title' => 'Original']);
    $success = now()->subHour()->startOfSecond();
    DataSource::find('gdacs')->update(['last_success_at' => $success, 'last_status' => 'success']);

    Http::fake([GDACS_URL => Http::response('boom', 500)]);

    $run = app(DisasterSyncService::class)->sync('gdacs');
    $source = DataSource::find('gdacs');

    expect($run->status)->toBe('failed')
        ->and($run->http_status)->toBe(500)
        ->and($run->error_message)->toContain('HTTP 500')
        ->and($source->last_status)->toBe('failed')
        ->and($source->consecutive_failures)->toBe(1)
        ->and($source->last_error)->toContain('HTTP 500')
        ->and($source->last_success_at->equalTo($success))->toBeTrue()
        ->and(DisasterEvent::count())->toBe(1)
        ->and(DisasterEvent::first()->title)->toBe('Original');

    Http::assertSentCount(3);
});

test('a connection error fails the run after retries', function () {
    Http::fake([USGS_URL => fn () => throw new ConnectionException('timeout')]);

    $run = app(DisasterSyncService::class)->sync('usgs');

    expect($run->status)->toBe('failed')
        ->and($run->http_status)->toBeNull()
        ->and(DataSource::find('usgs')->consecutive_failures)->toBe(1);

    Sleep::assertSleptTimes(2);
});

test('a rate limit or server error is retried and a later success is recorded', function () {
    Http::fake([GDACS_URL => Http::sequence()
        ->push('slow down', 429)
        ->push('oops', 503)
        ->push(gdacsResponse([gdacsFeature()]))
        ->whenEmpty(Http::response('', 204)),
    ]);

    $run = app(DisasterSyncService::class)->sync('gdacs');

    expect($run->status)->toBe('success')
        ->and(DisasterEvent::count())->toBe(1);

    Http::assertSentCount(4);
    Sleep::assertSleptTimes(2);
});

test('a client error is not retried', function () {
    Http::fake([GDACS_URL => Http::response('nope', 404)]);

    $run = app(DisasterSyncService::class)->sync('gdacs');

    expect($run->status)->toBe('failed');

    Http::assertSentCount(1);
});

test('an unexpected response shape fails the run', function (string $source, string $pattern) {
    Http::fake([$pattern => Http::response(['unexpected' => true])]);

    $run = app(DisasterSyncService::class)->sync($source);

    expect($run->status)->toBe('failed')
        ->and($run->error_message)->toContain('unexpected response shape');
})->with([['gdacs', GDACS_URL], ['usgs', USGS_URL]]);

test('an empty gdacs response is a success with zero events', function () {
    Http::fake([GDACS_URL => Http::response('', 204)]);

    $run = app(DisasterSyncService::class)->sync('gdacs');
    $source = DataSource::find('gdacs');

    expect($run->status)->toBe('success')
        ->and($run->fetched_count)->toBe(0)
        ->and($source->last_success_at)->not->toBeNull()
        ->and($source->last_empty_success_at)->not->toBeNull();
});

test('a success resets failures and records counts', function () {
    DataSource::find('usgs')->update(['consecutive_failures' => 4, 'last_error' => 'old', 'last_status' => 'failed']);
    Http::fake([USGS_URL => Http::response(usgsResponse([usgsFeature('a'), usgsFeature('b')]))]);

    app(DisasterSyncService::class)->sync('usgs');
    $source = DataSource::find('usgs');

    expect($source->consecutive_failures)->toBe(0)
        ->and($source->last_error)->toBeNull()
        ->and($source->last_status)->toBe('success')
        ->and($source->last_fetched_count)->toBe(2)
        ->and($source->last_processed_count)->toBe(2)
        ->and($source->last_empty_success_at)->toBeNull()
        ->and(SyncRun::where('source_key', 'usgs')->count())->toBe(1);
});

test('a successful sync that created an event clears the dashboard cache', function () {
    Cache::put('dashboard:current:v1', ['stale' => true], 300);
    Http::fake([GDACS_URL => Http::response(gdacsResponse([gdacsFeature()]))]);

    app(DisasterSyncService::class)->sync('gdacs');

    expect(Cache::has('dashboard:current:v1'))->toBeFalse();
});

test('a run is skipped while another run of the same source holds the lock', function () {
    Http::fake();
    $lock = Cache::lock('sync:gdacs', 60);
    $lock->get();

    expect(app(DisasterSyncService::class)->sync('gdacs'))->toBeNull();

    Http::assertNothingSent();
    expect(SyncRun::count())->toBe(0);

    $lock->release();
});

test('an unknown source is rejected', function () {
    expect(fn () => app(DisasterSyncService::class)->sync('eonet'))->toThrow(InvalidArgumentException::class);
});

test('syncing never calls NASA EONET', function () {
    Http::fake([
        GDACS_URL => Http::response(gdacsResponse([])),
        USGS_URL => Http::response(usgsResponse([])),
    ]);

    $this->artisan('disaster:sync')->assertSuccessful();

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'eonet'));
    Http::assertSentCount(3);
});

test('the sync command reports success and failure through its exit code', function () {
    Http::fake([
        GDACS_URL => Http::response(gdacsResponse([gdacsFeature()])),
        USGS_URL => Http::response('down', 500),
    ]);

    $this->artisan('disaster:sync gdacs')->assertSuccessful();
    $this->artisan('disaster:sync usgs')->assertFailed();
    $this->artisan('disaster:sync eonet')->assertFailed();
});

test('the sync command skips a locked source without failing', function () {
    Http::fake();
    $lock = Cache::lock('sync:usgs', 60);
    $lock->get();

    $this->artisan('disaster:sync usgs')->expectsOutputToContain('already running')->assertSuccessful();

    $lock->release();
});

test('both syncs are scheduled without overlapping', function () {
    $events = collect(app(Schedule::class)->events());

    $gdacs = $events->first(fn ($event) => str_contains($event->command, 'disaster:sync gdacs'));
    $usgs = $events->first(fn ($event) => str_contains($event->command, 'disaster:sync usgs'));

    expect($gdacs)->not->toBeNull()
        ->and($gdacs->expression)->toBe('2,17,32,47 * * * *')
        ->and($gdacs->withoutOverlapping)->toBeTrue()
        ->and($usgs)->not->toBeNull()
        ->and($usgs->expression)->toBe('4-59/5 * * * *')
        ->and($usgs->withoutOverlapping)->toBeTrue();
});

test('source urls are validated before they are stored', function (?string $input, ?string $expected) {
    expect(SafeUrl::validate($input, ['gdacs.org', 'usgs.gov']))->toBe($expected);
})->with([
    'https gdacs' => ['https://www.gdacs.org/report.aspx?eventid=1&eventtype=TC', 'https://www.gdacs.org/report.aspx?eventid=1&eventtype=TC'],
    'http is upgraded' => ['http://earthquake.usgs.gov/earthquakes/eventpage/us1', 'https://earthquake.usgs.gov/earthquakes/eventpage/us1'],
    'bare domain' => ['https://gdacs.org/x', 'https://gdacs.org/x'],
    'unknown host' => ['https://evil.example.com/x', null],
    'lookalike host' => ['https://notgdacs.org/x', null],
    'suffix trick' => ['https://gdacs.org.evil.com/x', null],
    'javascript scheme' => ['javascript:alert(1)', null],
    'credentials' => ['https://user:pw@gdacs.org/x', null],
    'empty' => ['', null],
    'null' => [null, null],
]);

test('gdacs fetches orange/red and green as separate requests using the eventlist filter', function () {
    fakeGdacs(gdacsFeed([gdacsFeature()]));

    app(DisasterSyncService::class)->sync('gdacs');

    $levels = Http::recorded()->map(function (array $pair) {
        parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

        return [$query['alertlevel'], $query['pagenumber'], $query['eventlist']];
    })->all();

    expect($levels)->toBe([
        ['orange;red', '1', 'EQ;TC;FL;VO;WF;DR'],
        ['green', '1', 'EQ;TC;FL;VO;WF;DR'],
    ]);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'eventtypes'));
});

test('green events are paged until a short page', function () {
    fakeGdacs(gdacsFeed(greenPages: [gdacsFeatures(100, 1), gdacsFeatures(30, 101)]));

    $run = app(DisasterSyncService::class)->sync('gdacs');

    expect($run->fetched_count)->toBe(130)
        ->and(DisasterEvent::count())->toBe(130);

    Http::assertSentCount(3);
});

test('paging stops when a page adds nothing new', function () {
    $page = gdacsFeatures(100, 1);
    fakeGdacs(gdacsFeed(greenPages: [$page, $page, $page, $page]));

    app(DisasterSyncService::class)->sync('gdacs');

    expect(DisasterEvent::count())->toBe(100);

    Http::assertSentCount(3);
});

test('green paging respects the page cap and logs a truncation warning', function () {
    Log::spy();
    config(['disaster_sources.sources.gdacs.max_green_pages' => 2]);
    fakeGdacs(gdacsFeed(greenPages: [gdacsFeatures(100, 1), gdacsFeatures(100, 101), gdacsFeatures(100, 201)]));

    app(DisasterSyncService::class)->sync('gdacs');

    expect(DisasterEvent::count())->toBe(200);

    Http::assertSentCount(3);
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'truncated'))->once();
});

test('an event present at both levels is stored with its Orange/Red version', function () {
    fakeGdacs(gdacsFeed(
        [gdacsFeature(['properties' => ['alertlevel' => 'Orange']])],
        [[gdacsFeature(['properties' => ['alertlevel' => 'Green']])]],
    ));

    app(DisasterSyncService::class)->sync('gdacs');

    expect(DisasterEvent::count())->toBe(1)
        ->and(DisasterEvent::first()->alert_level)->toBe('Orange');
});

test('a failed green request does not fail the run or lose Orange/Red events', function () {
    Log::spy();
    Http::fake([GDACS_URL => function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query['alertlevel'] === 'green'
            ? Http::response('down', 500)
            : Http::response(gdacsResponse([gdacsFeature(['properties' => ['alertlevel' => 'Red']])]));
    }]);

    $run = app(DisasterSyncService::class)->sync('gdacs');

    expect($run->status)->toBe('success')
        ->and(DisasterEvent::count())->toBe(1)
        ->and(DisasterEvent::first()->alert_level)->toBe('Red');

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'Green events could not be fetched'))->once();
});

test('a failed Orange/Red request fails the whole run', function () {
    Http::fake([GDACS_URL => function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query['alertlevel'] === 'green'
            ? Http::response(gdacsResponse(gdacsFeatures(3)))
            : Http::response('down', 500);
    }]);

    $run = app(DisasterSyncService::class)->sync('gdacs');

    expect($run->status)->toBe('failed')
        ->and(DisasterEvent::count())->toBe(0);

    Http::assertSentCount(3);
});

test('Retry-After seconds on a 429 are honoured exactly', function () {
    Http::fake([GDACS_URL => Http::sequence()
        ->push('', 429, ['Retry-After' => '3'])
        ->push(gdacsResponse([gdacsFeature()]))
        ->whenEmpty(Http::response('', 204)),
    ]);

    $run = app(DisasterSyncService::class)->sync('gdacs');

    expect($run->status)->toBe('success');

    Sleep::assertSleptTimes(1);
    Sleep::assertSlept(fn (CarbonInterval $duration) => (int) $duration->totalMilliseconds === 3000, 1);
});

test('Retry-After as an HTTP date is honoured', function () {
    $this->freezeTime();

    Http::fake([USGS_URL => Http::sequence()
        ->push('', 429, ['Retry-After' => now()->addSeconds(5)->utc()->toRfc7231String()])
        ->push(usgsResponse([usgsFeature('us1')])),
    ]);

    $run = app(DisasterSyncService::class)->sync('usgs');

    expect($run->status)->toBe('success');

    Sleep::assertSlept(fn (CarbonInterval $duration) => (int) $duration->totalMilliseconds === 5000, 1);
});

test('a 429 without Retry-After uses the configured delay', function () {
    Http::fake([USGS_URL => Http::sequence()
        ->push('', 429)
        ->push(usgsResponse([usgsFeature('us1')])),
    ]);

    app(DisasterSyncService::class)->sync('usgs');

    Sleep::assertSlept(fn (CarbonInterval $duration) => (int) $duration->totalMilliseconds === 2000, 1);
});

test('a Retry-After longer than the cap is not waited for and fails the run', function () {
    Http::fake([GDACS_URL => Http::sequence()
        ->push('', 429, ['Retry-After' => '120'])
        ->push(gdacsResponse([gdacsFeature()])),
    ]);

    $run = app(DisasterSyncService::class)->sync('gdacs');

    expect($run->status)->toBe('failed')
        ->and($run->http_status)->toBe(429)
        ->and($run->error_message)->toContain('Retry-After 120s')
        ->and(DisasterEvent::count())->toBe(0);

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

test('Retry-After is capped by configuration and an invalid value falls back to the delays', function () {
    config([
        'disaster_sources.max_retry_after_seconds' => 5,
        'disaster_sources.sources.usgs.retry_delays_ms' => [100, 777],
    ]);

    Http::fake([USGS_URL => Http::sequence()
        ->push('', 429, ['Retry-After' => '5'])
        ->push('', 429, ['Retry-After' => 'soon'])
        ->push(usgsResponse([usgsFeature('us1')])),
    ]);

    $run = app(DisasterSyncService::class)->sync('usgs');

    expect($run->status)->toBe('success');

    Sleep::assertSleptTimes(2);
    Sleep::assertSlept(fn (CarbonInterval $duration) => (int) $duration->totalMilliseconds === 5000, 1);
    Sleep::assertSlept(fn (CarbonInterval $duration) => (int) $duration->totalMilliseconds === 777, 1);
});

test('a synced GDACS event takes its country from the source ISO3', function () {
    $phl = Country::factory()->create(['iso3' => 'PHL']);
    Http::fake([GDACS_URL => Http::response(gdacsResponse([gdacsFeature()]))]);

    app(DisasterSyncService::class)->sync('gdacs');

    $event = DisasterEvent::first();

    expect($event->country_id)->toBe($phl->id)
        ->and($event->country_status)->toBe('resolved')
        ->and($event->country_method)->toBe('source_code');
});

test('a USGS earthquake is resolved from coordinates, never from its place text', function () {
    $idn = Country::factory()->create(['iso3' => 'IDN']);
    CountryBoundary::factory()->create([
        'country_id' => $idn->id,
        'geojson' => json_encode(['type' => 'Polygon', 'coordinates' => [[[99, -3], [102, -3], [102, 0], [99, 0], [99, -3]]]]),
        'min_lng' => 99, 'max_lng' => 102, 'min_lat' => -3, 'max_lat' => 0,
    ]);
    Http::fake([USGS_URL => Http::response(usgsResponse([
        usgsFeature('us-inland'),
        usgsFeature('us-sea', [], [103.0, -1.0, 10.0]),
    ]))]);

    app(DisasterSyncService::class)->sync('usgs');

    $inland = DisasterEvent::where('external_id', 'us-inland')->first();
    $sea = DisasterEvent::where('external_id', 'us-sea')->first();

    expect($inland->country_id)->toBe($idn->id)
        ->and($inland->country_method)->toBe('adm0_lookup')
        ->and($sea->country_id)->toBeNull()
        ->and($sea->country_status)->toBe('unresolved');
});

test('a resolved country is kept on later syncs, an unresolved one is retried when boundaries appear', function () {
    $idn = Country::factory()->create(['iso3' => 'IDN']);
    Http::fake([USGS_URL => Http::response(usgsResponse([usgsFeature('us-late')]))]);

    app(DisasterSyncService::class)->sync('usgs');
    expect(DisasterEvent::first()->country_status)->toBe('unresolved');

    CountryBoundary::factory()->create([
        'country_id' => $idn->id,
        'geojson' => json_encode(['type' => 'Polygon', 'coordinates' => [[[99, -3], [102, -3], [102, 0], [99, 0], [99, -3]]]]),
        'min_lng' => 99, 'max_lng' => 102, 'min_lat' => -3, 'max_lat' => 0,
    ]);

    app(DisasterSyncService::class)->sync('usgs');
    expect(DisasterEvent::first()->country_id)->toBe($idn->id);

    DisasterEvent::query()->update(['country_note' => 'kept']);
    app(DisasterSyncService::class)->sync('usgs');
    expect(DisasterEvent::first()->country_note)->toBe('kept');
});

/*
 * Follow-up 1: meaningful change detection and conditional cache invalidation (HLD v1.4 §28.6).
 */

/**
 * Http::fake() keeps the first matching stub, so a test that changes the response between syncs starts afresh.
 */
function resetHttp(): void
{
    Http::swap(new Factory);
    Http::preventStrayRequests();
}

function syncGdacsWith(array $feature): SyncRun
{
    resetHttp();
    Http::fake([GDACS_URL => Http::response(gdacsResponse([$feature]))]);

    return app(DisasterSyncService::class)->sync('gdacs');
}

test('a resync with nothing meaningful changed counts no update and keeps the cache', function () {
    syncGdacsWith(gdacsFeature());
    $before = DisasterEvent::first();

    $this->travel(10)->minutes();
    Cache::put('dashboard:current:v1', ['cached' => true], 300);
    // Only values that are not meaningful differ: raw_payload (an extra unmapped property) and the seen timestamps.
    $run = syncGdacsWith(gdacsFeature(['properties' => ['unmapped_noise' => 'changes every poll']]));
    $after = DisasterEvent::first();

    expect($run->status)->toBe('success')
        ->and($run->created_count)->toBe(0)
        ->and($run->updated_count)->toBe(0)
        ->and($run->processed_count)->toBe(0)
        ->and(Cache::get('dashboard:current:v1'))->toBe(['cached' => true])
        ->and($after->last_seen_at->isAfter($before->last_seen_at))->toBeTrue()
        ->and($after->raw_payload)->not->toEqual($before->raw_payload);
});

test('an empty successful sync keeps the cache', function () {
    Cache::put('dashboard:current:v1', ['cached' => true], 300);
    resetHttp();
    Http::fake([GDACS_URL => Http::response(gdacsResponse([]))]);

    app(DisasterSyncService::class)->sync('gdacs');

    expect(Cache::get('dashboard:current:v1'))->toBe(['cached' => true]);
});

test('a meaningful change counts one update and clears the cache', function (array $change) {
    syncGdacsWith(gdacsFeature());
    Cache::put('dashboard:current:v1', ['cached' => true], 300);

    $run = syncGdacsWith(gdacsFeature($change));

    expect($run->created_count)->toBe(0)
        ->and($run->updated_count)->toBe(1)
        ->and($run->processed_count)->toBe(1)
        ->and(Cache::has('dashboard:current:v1'))->toBeFalse();
})->with([
    'alert level' => [['properties' => ['alertlevel' => 'Red']]],
    'title' => [['properties' => ['name' => 'Tropical Cyclone RENAMED']]],
    'severity' => [['properties' => ['severitydata' => ['severity' => 190.0, 'severitytext' => 'Typhoon (190 km/h)']]]],
    'coordinates' => [['geometry' => ['type' => 'Point', 'coordinates' => [126.0, 13.0]]]],
    'end of event' => [['properties' => ['iscurrent' => 'false']]],
    'source link' => [['properties' => ['url' => ['report' => 'https://www.gdacs.org/report.aspx?eventid=1&episodeid=99&eventtype=TC']]]],
    'source update time' => [['properties' => ['datemodified' => '2026-11-09T09:00:00']]],
    'country code in source_meta' => [['properties' => ['iso3' => 'VNM']]],
    'affected countries in source_meta' => [['properties' => ['affectedcountries' => [['iso2' => 'PH', 'iso3' => 'PHL', 'countryname' => 'Philippines'], ['iso2' => 'VN', 'iso3' => 'VNM', 'countryname' => 'Viet Nam']]]]],
]);

test('a USGS status or magnitude revision is meaningful', function () {
    resetHttp();
    Http::fake([USGS_URL => Http::response(usgsResponse([usgsFeature('us1', ['status' => 'automatic', 'mag' => 5.6])]))]);
    app(DisasterSyncService::class)->sync('usgs');

    resetHttp();
    Http::fake([USGS_URL => Http::response(usgsResponse([usgsFeature('us1', ['status' => 'reviewed', 'mag' => 5.6])]))]);
    $statusRun = app(DisasterSyncService::class)->sync('usgs');

    resetHttp();
    Http::fake([USGS_URL => Http::response(usgsResponse([usgsFeature('us1', ['status' => 'reviewed', 'mag' => 5.9])]))]);
    $magnitudeRun = app(DisasterSyncService::class)->sync('usgs');

    resetHttp();
    Http::fake([USGS_URL => Http::response(usgsResponse([usgsFeature('us1', ['status' => 'reviewed', 'mag' => 5.9])]))]);
    $sameRun = app(DisasterSyncService::class)->sync('usgs');

    expect([$statusRun->updated_count, $magnitudeRun->updated_count, $sameRun->updated_count])->toBe([1, 1, 0]);
});

test('new events and updates are counted separately in one run', function () {
    syncGdacsWith(gdacsFeature());

    resetHttp();
    Http::fake([GDACS_URL => Http::response(gdacsResponse([
        gdacsFeature(['properties' => ['alertlevel' => 'Red']]),
        gdacsFeature(['properties' => ['eventid' => 2002, 'name' => 'Another']]),
    ]))]);
    $run = app(DisasterSyncService::class)->sync('gdacs');

    expect($run->created_count)->toBe(1)->and($run->updated_count)->toBe(1)->and($run->processed_count)->toBe(2);
});

test('a country change counts as an update and clears the old and new country entries', function () {
    $phl = Country::factory()->create(['iso3' => 'PHL']);
    $vnm = Country::factory()->create(['iso3' => 'VNM']);
    syncGdacsWith(gdacsFeature());
    expect(DisasterEvent::first()->country_id)->toBe($phl->id);

    Cache::put("country-alerts:{$phl->id}:v1", 'phl', 60);
    Cache::put("country-alerts:{$vnm->id}:v1", 'vnm', 60);
    Cache::put('country-alerts:999:v1', 'other', 60);

    $run = syncGdacsWith(gdacsFeature(['properties' => ['iso3' => 'VNM']]));

    expect($run->updated_count)->toBe(1)
        ->and(DisasterEvent::first()->country_id)->toBe($vnm->id)
        ->and(Cache::has("country-alerts:{$phl->id}:v1"))->toBeFalse()
        ->and(Cache::has("country-alerts:{$vnm->id}:v1"))->toBeFalse()
        ->and(Cache::get('country-alerts:999:v1'))->toBe('other');
});

test('a country entry is cleared only for countries whose events changed', function () {
    $phl = Country::factory()->create(['iso3' => 'PHL']);
    Cache::put("country-alerts:{$phl->id}:v1", 'phl', 60);

    syncGdacsWith(gdacsFeature());

    expect(Cache::has("country-alerts:{$phl->id}:v1"))->toBeFalse();

    Cache::put("country-alerts:{$phl->id}:v1", 'phl', 60);
    syncGdacsWith(gdacsFeature(['properties' => ['unmapped_noise' => 'x']]));

    expect(Cache::get("country-alerts:{$phl->id}:v1"))->toBe('phl');
});

test('a failed sync keeps the cache and every stored event unchanged', function () {
    syncGdacsWith(gdacsFeature());
    $before = DisasterEvent::first()->toArray();
    Cache::put('dashboard:current:v1', ['cached' => true], 300);

    resetHttp();
    Http::fake([GDACS_URL => Http::response('boom', 500)]);
    $run = app(DisasterSyncService::class)->sync('gdacs');

    expect($run->status)->toBe('failed')
        ->and(Cache::get('dashboard:current:v1'))->toBe(['cached' => true])
        ->and(DisasterEvent::first()->toArray())->toBe($before);
});

test('a database failure while saving rolls everything back and keeps the cache', function () {
    syncGdacsWith(gdacsFeature());
    Cache::put('dashboard:current:v1', ['cached' => true], 300);
    $before = DisasterEvent::first()->toArray();

    DB::listen(function ($query) {
        if (str_contains($query->sql, 'update `disaster_events`') || str_contains($query->sql, 'update "disaster_events"')) {
            throw new RuntimeException('simulated failure after the upsert');
        }
    });

    resetHttp();
    Http::fake([GDACS_URL => Http::response(gdacsResponse([gdacsFeature(['properties' => ['iso3' => 'VNM', 'name' => 'Changed']])]))]);
    $run = app(DisasterSyncService::class)->sync('gdacs');

    expect($run->status)->toBe('failed')
        ->and($run->error_message)->toContain('simulated failure')
        ->and(Cache::get('dashboard:current:v1'))->toBe(['cached' => true])
        ->and(DisasterEvent::first()->toArray())->toBe($before);
});

/*
 * Follow-up 2: scheduler overlap protection.
 */

test('the scheduled syncs expire their overlap mutex after 10 minutes, longer than the service lock', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command, 'disaster:sync'));

    expect($events)->toHaveCount(2);

    foreach ($events as $event) {
        expect($event->withoutOverlapping)->toBeTrue()
            ->and($event->expiresAt)->toBe(10)
            ->and($event->expiresAt * 60)->toBeGreaterThan(config('disaster_sources.sync.lock_seconds'));
    }
});

test('the service lock still blocks a second run when the scheduler mutex is not held', function () {
    $lock = Cache::lock('sync:gdacs', 300);
    $lock->get();
    resetHttp();
    Http::fake();

    expect(app(DisasterSyncService::class)->sync('gdacs'))->toBeNull();
    Http::assertNothingSent();

    $lock->release();
});

/*
 * Follow-up 3: total sync time limit and compatible lock duration (HLD v1.4 §28.9).
 */

test('the timing values are consistent with the request and retry configuration', function () {
    $sync = config('disaster_sources.sync');
    $gdacs = config('disaster_sources.sources.gdacs');
    $attempts = count($gdacs['retry_delays_ms']) + 1;

    $worstRequest = $attempts * $gdacs['timeout'] + ($attempts - 1) * config('disaster_sources.max_retry_after_seconds');
    $unboundedGdacs = (3 + $gdacs['max_green_pages']) * $worstRequest;
    $maxRun = $sync['deadline_seconds'] + $gdacs['timeout'] + $sync['persist_allowance_seconds'];

    expect($unboundedGdacs)->toBeGreaterThan($sync['lock_seconds'])          // why a deadline is needed
        ->and($maxRun)->toBeLessThan($sync['job_timeout_seconds'])
        ->and($sync['job_timeout_seconds'])->toBeLessThan($sync['lock_seconds'])
        ->and($sync['lock_seconds'])->toBeLessThan(config('queue.connections.database.retry_after'))
        ->and(config('queue.connections.database.retry_after'))->toBeGreaterThan($sync['job_timeout_seconds'])
        ->and(config('disaster_sources.sources.usgs.timeout'))->toBeLessThanOrEqual($gdacs['timeout']);
});

test('the service lock uses the configured duration', function () {
    config(['disaster_sources.sync.lock_seconds' => 123]);
    resetHttp();
    Http::fake([USGS_URL => function () {
        $probe = Cache::lock('sync:usgs', 5);
        expect($probe->get())->toBeFalse();   // held while fetching

        return Http::response(usgsResponse([]));
    }]);

    $run = app(DisasterSyncService::class)->sync('usgs');

    expect($run->status)->toBe('success')
        ->and(Cache::lock('sync:usgs', 5)->get())->toBeTrue();    // released afterwards
});

test('retries stop when the next wait would pass the deadline and the run fails without changes', function () {
    Sleep::fake(syncWithCarbon: true);
    config(['disaster_sources.sync.deadline_seconds' => 3]);
    syncGdacsWith(gdacsFeature());
    $before = DisasterEvent::first()->toArray();
    Cache::put('dashboard:current:v1', ['cached' => true], 300);

    resetHttp();
    Http::fake([GDACS_URL => Http::response('down', 503)]);
    $run = app(DisasterSyncService::class)->sync('gdacs');

    // attempt 1 fails, waits 2 s, attempt 2 fails; the next wait (5 s) would pass the 3 s deadline.
    Http::assertSentCount(2);
    Sleep::assertSleptTimes(1);

    expect($run->status)->toBe('failed')
        ->and($run->error_message)->toContain('total time limit of 3 s')
        ->and(DataSource::find('gdacs')->consecutive_failures)->toBe(1)
        ->and(DisasterEvent::first()->toArray())->toBe($before)
        ->and(Cache::get('dashboard:current:v1'))->toBe(['cached' => true]);
});

test('no request starts once the deadline has passed', function () {
    config(['disaster_sources.sync.deadline_seconds' => 0]);
    resetHttp();
    Http::fake();

    $run = app(DisasterSyncService::class)->sync('usgs');

    Http::assertNothingSent();
    expect($run->status)->toBe('failed')->and($run->error_message)->toContain('total time limit');
});

test('a Retry-After wait that would pass the deadline is not taken', function () {
    Sleep::fake(syncWithCarbon: true);
    config(['disaster_sources.sync.deadline_seconds' => 10]);
    resetHttp();
    Http::fake([USGS_URL => Http::response('slow down', 429, ['Retry-After' => '20'])]);

    $run = app(DisasterSyncService::class)->sync('usgs');

    Sleep::assertNeverSlept();
    Http::assertSentCount(1);
    expect($run->status)->toBe('failed')->and($run->error_message)->toContain('total time limit');
});

test('a Green cut off by the deadline keeps the Orange/Red events and succeeds', function () {
    Log::spy();
    config(['disaster_sources.sync.deadline_seconds' => 100]);

    resetHttp();
    Http::fake([GDACS_URL => function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        if ($query['alertlevel'] === 'green') {
            return Http::response(gdacsResponse([]));
        }

        $this->travel(101)->seconds();   // the Orange/Red request used up the whole budget

        return Http::response(gdacsResponse([gdacsFeature(['properties' => ['alertlevel' => 'Red']])]));
    }]);

    $run = app(DisasterSyncService::class)->sync('gdacs');

    Http::assertSentCount(1);
    expect($run->status)->toBe('success')
        ->and(DisasterEvent::count())->toBe(1)
        ->and(DisasterEvent::first()->alert_level)->toBe('Red');

    Log::shouldHaveReceived('warning')->withArgs(fn (string $m) => str_contains($m, 'Green events could not be fetched') && str_contains($m, 'total time limit'))->once();
});

test('an unfinished Orange/Red level fails the run when the deadline passes', function () {
    config(['disaster_sources.sync.deadline_seconds' => 100]);
    $calls = 0;

    resetHttp();
    Http::fake([GDACS_URL => function () use (&$calls) {
        $calls++;
        $this->travel(101)->seconds();

        return Http::response(gdacsResponse(gdacsFeatures(100, 1, 'Orange')));   // a full page: another page is needed
    }]);

    $run = app(DisasterSyncService::class)->sync('gdacs');

    expect($run->status)->toBe('failed')
        ->and($calls)->toBe(1)
        ->and($run->error_message)->toContain('total time limit')
        ->and(DisasterEvent::count())->toBe(0);
});

test('the deadline is cleared after a run so a later direct fetch is not bounded', function () {
    resetHttp();
    Http::fake([USGS_URL => Http::response(usgsResponse([]))]);
    app(DisasterSyncService::class)->sync('usgs');

    $this->travel(10)->minutes();

    expect(fn () => app(UsgsClient::class)->fetch())->not->toThrow(SourceFetchException::class);
});
