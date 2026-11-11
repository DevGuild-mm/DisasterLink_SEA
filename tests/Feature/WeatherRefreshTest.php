<?php

use App\Jobs\RunSourceSync;
use App\Models\CapitalWeather;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\Disaster\Dashboard\DashboardCache;
use App\Services\Disaster\Dashboard\SourceHealthService;
use App\Services\Disaster\Sync\DisasterSyncService;
use App\Services\Disaster\Weather\WeatherRefreshService;
use Database\Seeders\CountrySeeder;
use Database\Seeders\DataSourceSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

const OPEN_METEO_URL = 'https://api.open-meteo.com/v1/forecast*';

beforeEach(function () {
    (new CountrySeeder)->run();
    (new DataSourceSeeder)->run();
    Sleep::fake();
    Cache::flush();
});

/**
 * @return array<int, array<string, mixed>>
 */
function openMeteoBody(float $temperature = 30.4, int $count = 11): array
{
    return array_map(fn (int $i): array => [
        'utc_offset_seconds' => 25200,
        'current' => [
            'time' => '2026-11-10T12:00',
            'temperature_2m' => $temperature + $i / 10,
            'relative_humidity_2m' => 76,
            'precipitation' => 0.2,
            'weather_code' => 61,
            'wind_speed_10m' => 14.5,
        ],
        'daily' => ['temperature_2m_max' => [32.5], 'temperature_2m_min' => [25.0]],
    ], range(1, $count));
}

/**
 * Replace the faked Open-Meteo response (Http::fake keeps the first matching stub).
 */
function fakeOpenMeteo(mixed $response): void
{
    Http::swap(new Factory);
    Http::fake([OPEN_METEO_URL => $response]);
}

function refreshWeather(): SyncRun
{
    return app(WeatherRefreshService::class)->refresh();
}

test('one batched request stores a row per capital and records the run', function () {
    fakeOpenMeteo(Http::response(openMeteoBody()));

    $run = refreshWeather();

    expect($run->status)->toBe('success')
        ->and($run->fetched_count)->toBe(11)
        ->and($run->updated_count)->toBe(11)
        ->and(CapitalWeather::count())->toBe(11)
        ->and(DataSource::find('open_meteo')->last_status)->toBe('success')
        ->and(DataSource::find('open_meteo')->last_success_at)->not->toBeNull();

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        $latitudes = explode(',', $request['latitude']);

        return str_starts_with($request->url(), 'https://') && count($latitudes) === 11 && $request['forecast_days'] === 1;
    });

    $manila = CapitalWeather::where('country_id', Country::where('iso3', 'PHL')->value('id'))->first();
    expect($manila->condition)->toBe('Slight rain')
        ->and($manila->icon)->toBe('cloud-rain')
        ->and($manila->observed_at->toIso8601String())->toBe('2026-11-10T05:00:00+00:00');
});

test('an unchanged repeat refresh counts no change and keeps the dashboard cache', function () {
    fakeOpenMeteo(Http::response(openMeteoBody()));
    refreshWeather();
    $generation = app(DashboardCache::class)->generation();

    $run = refreshWeather();

    expect($run->status)->toBe('success')
        ->and($run->updated_count)->toBe(0)
        ->and(app(DashboardCache::class)->generation())->toBe($generation);
});

test('a changed value updates the snapshot and moves the dashboard to a new generation', function () {
    fakeOpenMeteo(Http::response(openMeteoBody()));
    refreshWeather();
    $generation = app(DashboardCache::class)->generation();
    Cache::put(DashboardCache::WEATHER_KEY, ['old'], 60);

    fakeOpenMeteo(Http::response(openMeteoBody(33.0)));
    $run = refreshWeather();

    expect($run->updated_count)->toBe(11)
        ->and(app(DashboardCache::class)->generation())->toBeGreaterThan($generation)
        ->and(Cache::has(DashboardCache::WEATHER_KEY))->toBeFalse();
});

test('a failed refresh keeps the stored weather and the cache and marks the source failed', function () {
    fakeOpenMeteo(Http::response(openMeteoBody()));
    refreshWeather();
    $before = CapitalWeather::query()->orderBy('country_id')->get()->toArray();
    $generation = app(DashboardCache::class)->generation();

    fakeOpenMeteo(Http::response('down', 503));
    $run = refreshWeather();

    expect($run->status)->toBe('failed')
        ->and($run->error_message)->toContain('HTTP 503')
        ->and(DataSource::find('open_meteo')->last_status)->toBe('failed')
        ->and(DataSource::find('open_meteo')->consecutive_failures)->toBe(1)
        ->and(CapitalWeather::query()->orderBy('country_id')->get()->toArray())->toBe($before)
        ->and(app(DashboardCache::class)->generation())->toBe($generation);
});

test('a response with no usable observations fails the run', function () {
    fakeOpenMeteo(Http::response([['current' => []]]));

    $run = refreshWeather();

    expect($run->status)->toBe('failed')
        ->and(CapitalWeather::count())->toBe(0);
});

test('a second refresh is skipped while the first holds the lock', function () {
    fakeOpenMeteo(Http::response(openMeteoBody()));
    $lock = Cache::lock('sync:open_meteo', 300);
    $lock->get();

    expect(app(WeatherRefreshService::class)->refresh())->toBeNull();

    Http::assertNothingSent();
    $lock->release();
});

test('the artisan command reports success and failure', function () {
    fakeOpenMeteo(Http::response(openMeteoBody()));
    $this->artisan('weather:refresh-capitals')->assertSuccessful();

    fakeOpenMeteo(Http::response('down', 503));
    $this->artisan('weather:refresh-capitals')->assertFailed();
});

test('the weather refresh is scheduled every 30 minutes without overlap', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e): bool => str_contains($e->command, 'weather:refresh-capitals'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('12,42 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

test('the dashboard shows the stored capital weather', function () {
    fakeOpenMeteo(Http::response(openMeteoBody()));
    refreshWeather();
    markSourcesSynced();
    Http::preventStrayRequests();

    $weather = $this->getJson('/api/disaster-dashboard')->assertOk()->json('weather');

    expect($weather)->toHaveCount(11)
        ->and(collect($weather)->firstWhere('iso3', 'PHL')['city'])->toBe('Manila')
        ->and(collect($weather)->firstWhere('iso3', 'PHL')['condition'])->toBe('Slight rain');
});

// ---- stale versus failed (the banner) ----

function markWeatherState(array $attributes): void
{
    DataSource::query()->where('key', 'open_meteo')->update($attributes);
}

test('old data without a recorded error is stale but carries no error', function () {
    markWeatherState(['last_attempt_at' => now()->subHours(3), 'last_success_at' => now()->subHours(3), 'last_status' => 'success', 'last_error' => null, 'consecutive_failures' => 0]);

    $weather = app(SourceHealthService::class)->snapshot()['sources']['weather'];

    expect($weather['is_stale'])->toBeTrue()
        ->and($weather['status'])->toBe('Stale')
        ->and($weather['error'])->toBeNull()
        ->and($weather['last_successful_fetch'])->not->toBeNull();
});

test('a real failed refresh records the error and the stale data still shows its last success', function () {
    markWeatherState(['last_success_at' => now()->subHours(3), 'last_attempt_at' => now(), 'last_status' => 'failed', 'last_error' => 'Open-Meteo returned HTTP 503.', 'consecutive_failures' => 1]);

    $weather = app(SourceHealthService::class)->snapshot()['sources']['weather'];

    expect($weather['is_stale'])->toBeTrue()->and($weather['error'])->toBe('Open-Meteo returned HTTP 503.');
});

test('the weather banner only claims a failed refresh when an error is recorded', function () {
    $source = file_get_contents(resource_path('js/components/disaster/WeatherMonitoring.vue'));

    expect($source)
        ->not->toContain("'Live Open-Meteo refresh failed.'")
        ->toContain('v-if="sourceMeta.error"')
        ->toContain('The last Open-Meteo refresh failed:')
        ->toContain('data-test="weather-stale-neutral"')
        ->toContain('Weather has not been refreshed recently.')
        ->toContain('formatTime(sourceMeta.last_successful_fetch)');
});

// ---- catch-up ----

test('a catch-up refreshes once when the stored weather is stale and records it as a catch-up', function () {
    markWeatherState(['last_attempt_at' => now()->subHours(3), 'last_success_at' => now()->subHours(3)]);
    fakeOpenMeteo(Http::response(openMeteoBody()));

    $run = app(WeatherRefreshService::class)->catchUp();

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe('success')
        ->and($run->trigger)->toBe('catch_up')
        ->and($run->http_status)->toBe(200)
        ->and(CapitalWeather::count())->toBe(11);

    Http::assertSentCount(1);
});

test('a catch-up does nothing while the stored weather is still recent', function () {
    markWeatherState(['last_attempt_at' => now()->subMinutes(40), 'last_success_at' => now()->subMinutes(40)]);
    fakeOpenMeteo(Http::response(openMeteoBody()));

    expect(app(WeatherRefreshService::class)->catchUp())->toBeNull();

    Http::assertNothingSent();
    expect(SyncRun::count())->toBe(0);
});

test('a catch-up on a database that never synced fills the weather once', function () {
    fakeOpenMeteo(Http::response(openMeteoBody()));

    expect(app(WeatherRefreshService::class)->catchUp())->not->toBeNull();

    Http::assertSentCount(1);
});

test('a catch-up does not retry inside the retry window after a failed attempt, and tries again after it', function () {
    markWeatherState(['last_success_at' => now()->subHours(3), 'last_attempt_at' => now()->subMinutes(10), 'last_status' => 'failed']);
    fakeOpenMeteo(Http::response(openMeteoBody()));

    expect(app(WeatherRefreshService::class)->catchUp())->toBeNull();
    Http::assertNothingSent();

    markWeatherState(['last_attempt_at' => now()->subMinutes(31)]);

    expect(app(WeatherRefreshService::class)->catchUp())->not->toBeNull();
    Http::assertSentCount(1);
});

test('repeated catch-up checks after a failed catch-up make no further requests (no retry loop)', function () {
    markWeatherState(['last_attempt_at' => now()->subHours(3), 'last_success_at' => now()->subHours(3)]);
    fakeOpenMeteo(Http::response('down', 503));

    $first = app(WeatherRefreshService::class)->catchUp();
    $requestsAfterFirst = count(Http::recorded());

    foreach (range(1, 5) as $ignored) {
        expect(app(WeatherRefreshService::class)->catchUp())->toBeNull();
    }

    expect($first->status)->toBe('failed')
        ->and($first->trigger)->toBe('catch_up')
        ->and($first->http_status)->toBe(503)
        ->and(count(Http::recorded()))->toBe($requestsAfterFirst)
        ->and(SyncRun::count())->toBe(1);
});

test('a catch-up is skipped while a scheduled or manual refresh holds the lock', function () {
    markWeatherState(['last_attempt_at' => now()->subHours(3), 'last_success_at' => now()->subHours(3)]);
    fakeOpenMeteo(Http::response(openMeteoBody()));
    $lock = Cache::lock('sync:open_meteo', 300);
    $lock->get();

    expect(app(WeatherRefreshService::class)->catchUp())->toBeNull();

    Http::assertNothingSent();
    $lock->release();
});

test('a catch-up right after a scheduled refresh sees fresh data and makes no second request', function () {
    markWeatherState(['last_attempt_at' => now()->subHours(3), 'last_success_at' => now()->subHours(3)]);
    fakeOpenMeteo(Http::response(openMeteoBody()));

    refreshWeather();
    $requests = count(Http::recorded());

    expect(app(WeatherRefreshService::class)->catchUp())->toBeNull()
        ->and(count(Http::recorded()))->toBe($requests);
});

test('a failed catch-up keeps the last successful observations', function () {
    fakeOpenMeteo(Http::response(openMeteoBody()));
    refreshWeather();
    $before = CapitalWeather::query()->orderBy('country_id')->get()->toArray();
    markWeatherState(['last_attempt_at' => now()->subHours(3), 'last_success_at' => now()->subHours(3)]);

    fakeOpenMeteo(Http::response('down', 503));
    $run = app(WeatherRefreshService::class)->catchUp();

    expect($run->status)->toBe('failed')
        ->and(CapitalWeather::query()->orderBy('country_id')->get()->toArray())->toBe($before)
        ->and(DataSource::find('open_meteo')->last_status)->toBe('failed');
});

test('the catch-up command only refreshes when needed', function () {
    fakeOpenMeteo(Http::response(openMeteoBody()));
    markWeatherState(['last_attempt_at' => now()->subMinutes(5), 'last_success_at' => now()->subMinutes(5)]);

    $this->artisan('weather:refresh-capitals --catch-up')->expectsOutputToContain('No weather catch-up needed')->assertSuccessful();
    Http::assertNothingSent();

    markWeatherState(['last_attempt_at' => now()->subHours(2), 'last_success_at' => now()->subHours(2)]);
    $this->artisan('weather:refresh-capitals --catch-up')->assertSuccessful();
    Http::assertSentCount(1);
});

test('the catch-up check is scheduled every minute without overlap, next to the 30-minute refresh', function () {
    $events = collect(app(Schedule::class)->events());
    $regular = $events->first(fn ($e): bool => str_contains($e->command, 'weather:refresh-capitals') && ! str_contains($e->command, '--catch-up'));
    $catchUp = $events->first(fn ($e): bool => str_contains($e->command, 'weather:refresh-capitals --catch-up'));

    expect($regular->expression)->toBe('12,42 * * * *')
        ->and($catchUp)->not->toBeNull()
        ->and($catchUp->expression)->toBe('* * * * *')
        ->and($catchUp->withoutOverlapping)->toBeTrue();
});

// ---- admin manual refresh ----

function weatherAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

test('an admin can queue a manual Open-Meteo refresh, recorded with who asked', function () {
    Queue::fake();
    $admin = weatherAdmin();

    $response = $this->actingAs($admin)->postJson('/admin/data-sources/refresh', ['source' => 'open_meteo'])->assertStatus(202);

    $run = SyncRun::where('source_key', 'open_meteo')->sole();
    expect($response->json('results.open_meteo.state'))->toBe('queued')
        ->and($run->status)->toBe('queued')
        ->and($run->trigger)->toBe('manual')
        ->and($run->requested_by)->toBe($admin->id)
        ->and($run->request_uuid)->toBe($response->json('request_uuid'));

    Queue::assertPushed(RunSourceSync::class, fn (RunSourceSync $job): bool => $job->source === 'open_meteo' && $job->runId === $run->id);
    expect(CapitalWeather::count())->toBe(0);
});

test('the manual request does not call Open-Meteo itself', function () {
    Queue::fake();
    fakeOpenMeteo(Http::response(openMeteoBody()));

    $this->actingAs(weatherAdmin())->postJson('/admin/data-sources/refresh', ['source' => 'open_meteo'])->assertStatus(202);

    Http::assertNothingSent();
});

test('guests, ordinary users and unverified admins cannot trigger a weather refresh', function () {
    Queue::fake();

    $this->postJson('/admin/data-sources/refresh', ['source' => 'open_meteo'])->assertUnauthorized();
    $this->actingAs(User::factory()->create())->postJson('/admin/data-sources/refresh', ['source' => 'open_meteo'])->assertForbidden();
    $this->actingAs(User::factory()->unverified()->create(['role' => 'admin']))->postJson('/admin/data-sources/refresh', ['source' => 'open_meteo'])->assertStatus(403);

    Queue::assertNothingPushed();
    expect(SyncRun::count())->toBe(0);
});

test('a manual weather refresh is limited to three requests a minute', function () {
    Queue::fake();
    $admin = weatherAdmin();

    foreach (range(1, 3) as $ignored) {
        $this->actingAs($admin)->postJson('/admin/data-sources/refresh', ['source' => 'open_meteo'])->assertStatus(202);
        SyncRun::query()->delete();
    }

    $this->actingAs($admin)->postJson('/admin/data-sources/refresh', ['source' => 'open_meteo'])->assertStatus(429);
});

test('a second manual weather request is refused while one is queued or running', function () {
    Queue::fake();
    $admin = weatherAdmin();

    $this->actingAs($admin)->postJson('/admin/data-sources/refresh', ['source' => 'open_meteo'])->assertJsonPath('results.open_meteo.state', 'queued');
    $this->actingAs($admin)->postJson('/admin/data-sources/refresh', ['source' => 'open_meteo'])->assertJsonPath('results.open_meteo.state', 'already_queued');
    expect(SyncRun::count())->toBe(1);
    Queue::assertPushed(RunSourceSync::class, 1);

    SyncRun::query()->delete();
    $lock = Cache::lock('sync:open_meteo', 300);
    $lock->get();
    $this->actingAs($admin)->postJson('/admin/data-sources/refresh', ['source' => 'open_meteo'])->assertJsonPath('results.open_meteo.state', 'already_running');
    $lock->release();
});

test('a manual weather refresh right after a finished run is in cooldown', function () {
    Queue::fake();
    SyncRun::create(['source_key' => 'open_meteo', 'status' => 'success', 'started_at' => now()->subSeconds(20), 'finished_at' => now()->subSeconds(10)]);

    $this->actingAs(weatherAdmin())->postJson('/admin/data-sources/refresh', ['source' => 'open_meteo'])
        ->assertJsonPath('results.open_meteo.state', 'cooldown');

    Queue::assertNothingPushed();
});

test('the queued job runs the stored row through the weather service and keeps the admin on the record', function () {
    $admin = weatherAdmin();
    $run = SyncRun::create(['source_key' => 'open_meteo', 'status' => 'queued', 'trigger' => 'manual', 'requested_by' => $admin->id, 'queued_at' => now()]);
    fakeOpenMeteo(Http::response(openMeteoBody()));

    (new RunSourceSync('open_meteo', $run->id))->handle(app(DisasterSyncService::class));

    $run->refresh();
    expect(SyncRun::count())->toBe(1)
        ->and($run->status)->toBe('success')
        ->and($run->trigger)->toBe('manual')
        ->and($run->requested_by)->toBe($admin->id)
        ->and($run->http_status)->toBe(200)
        ->and($run->fetched_count)->toBe(11)
        ->and(CapitalWeather::count())->toBe(11);

    Http::assertSentCount(1);
});

test('a failed manual weather refresh records the error and keeps the last observations', function () {
    fakeOpenMeteo(Http::response(openMeteoBody()));
    refreshWeather();
    $before = CapitalWeather::query()->orderBy('country_id')->get()->toArray();
    $run = SyncRun::create(['source_key' => 'open_meteo', 'status' => 'queued', 'trigger' => 'manual', 'requested_by' => weatherAdmin()->id, 'queued_at' => now()]);

    fakeOpenMeteo(Http::response('down', 503));
    (new RunSourceSync('open_meteo', $run->id))->handle(app(DisasterSyncService::class));

    $run->refresh();
    expect($run->status)->toBe('failed')
        ->and($run->http_status)->toBe(503)
        ->and($run->error_message)->toContain('HTTP 503')
        ->and(CapitalWeather::query()->orderBy('country_id')->get()->toArray())->toBe($before);
});

test('a manual weather job that finds the lock taken removes its unclaimed row and fetches nothing', function () {
    $run = SyncRun::create(['source_key' => 'open_meteo', 'status' => 'queued', 'trigger' => 'manual', 'queued_at' => now()]);
    fakeOpenMeteo(Http::response(openMeteoBody()));
    $lock = Cache::lock('sync:open_meteo', 300);
    $lock->get();

    (new RunSourceSync('open_meteo', $run->id))->handle(app(DisasterSyncService::class));

    Http::assertNothingSent();
    expect(SyncRun::count())->toBe(0);
    $lock->release();
});

test('syncing only USGS still queues only USGS (the existing behaviour)', function () {
    Queue::fake();

    $this->actingAs(weatherAdmin())->postJson('/admin/data-sources/refresh', ['source' => 'usgs'])->assertStatus(202);

    expect(SyncRun::pluck('source_key')->all())->toBe(['usgs']);
    Queue::assertPushed(RunSourceSync::class, 1);
});

// ---- Data Freshness ----

test('Open-Meteo is a card in Data Freshness with its health, attempts, count and duration', function () {
    fakeOpenMeteo(Http::response(openMeteoBody()));
    refreshWeather();

    $cards = collect($this->actingAs(weatherAdmin())->getJson('/admin/data-sources')->assertOk()->json('sources'))->keyBy('key');
    $weather = $cards['open_meteo'];

    expect($cards->keys()->all())->toContain('open_meteo', 'gdacs', 'usgs')
        ->and($weather['name'])->toBe('Open-Meteo')
        ->and($weather['status'])->toBe('Healthy')
        ->and($weather['last_attempt_at'])->not->toBeNull()
        ->and($weather['last_success_at'])->not->toBeNull()
        ->and($weather['last_fetched_count'])->toBe(11)
        ->and($weather['capitals_with_data'])->toBe(11)
        ->and($weather['latest_run']['http_status'])->toBe(200)
        ->and($weather['latest_run']['duration_ms'])->not->toBeNull();
});

test('successful and failed Open-Meteo runs both appear in the history with HTTP status and error', function () {
    fakeOpenMeteo(Http::response(openMeteoBody()));
    refreshWeather();
    fakeOpenMeteo(Http::response('down', 503));
    refreshWeather();

    $runs = collect($this->actingAs(weatherAdmin())->getJson('/admin/data-sources')->json('runs'))->where('source_key', 'open_meteo')->values();

    expect($runs)->toHaveCount(2)
        ->and($runs[0]['state'])->toBe('failed')
        ->and($runs[0]['http_status'])->toBe(503)
        ->and($runs[0]['error_message'])->toContain('HTTP 503')
        ->and($runs[1]['state'])->toBe('success')
        ->and($runs[1]['http_status'])->toBe(200)
        ->and($runs[1]['fetched_count'])->toBe(11);
});

test('scheduled, catch-up and manual weather runs are told apart in the history', function () {
    $admin = weatherAdmin();
    fakeOpenMeteo(Http::response(openMeteoBody()));

    refreshWeather();
    markWeatherState(['last_attempt_at' => now()->subHours(2), 'last_success_at' => now()->subHours(2)]);
    app(WeatherRefreshService::class)->catchUp();
    $manual = SyncRun::create(['source_key' => 'open_meteo', 'status' => 'queued', 'trigger' => 'manual', 'requested_by' => $admin->id, 'queued_at' => now()]);
    app(WeatherRefreshService::class)->refresh($manual->id);

    $runs = collect($this->actingAs($admin)->getJson('/admin/data-sources')->json('runs'))->where('source_key', 'open_meteo');

    expect($runs->pluck('trigger')->sort()->values()->all())->toBe(['catch_up', 'manual', 'scheduler'])
        ->and($runs->firstWhere('trigger', 'manual')['requested_by'])->toBe($admin->name);
});

test('frequent USGS runs cannot push the Open-Meteo history out of the list', function () {
    SyncRun::create(['source_key' => 'open_meteo', 'status' => 'success', 'trigger' => 'scheduler', 'started_at' => now()->subHours(3), 'finished_at' => now()->subHours(3), 'http_status' => 200, 'fetched_count' => 11]);

    foreach (range(1, 30) as $i) {
        SyncRun::create(['source_key' => 'usgs', 'status' => 'success', 'trigger' => 'scheduler', 'started_at' => now()->subMinutes($i), 'finished_at' => now()->subMinutes($i)]);
    }

    $runs = collect($this->actingAs(weatherAdmin())->getJson('/admin/data-sources')->json('runs'));

    expect($runs->where('source_key', 'open_meteo'))->toHaveCount(1)
        ->and($runs->where('source_key', 'usgs'))->toHaveCount(10);
});

test('the Freshness panel offers Open-Meteo sync, a source filter and trigger labels', function () {
    $source = file_get_contents(resource_path('js/components/admin/FreshnessPanel.vue'));

    expect($source)
        ->toContain("sync('open_meteo')")
        ->toContain('data-test="sync-open-meteo"')
        ->toContain('history-filter-')
        ->toContain("'Catch-up (after a missed schedule)'")
        ->toContain("'Scheduled'")
        ->toContain('Manual (')
        ->toContain('http_status')
        ->not->toContain('Weather\n                runs on its own schedule');
});

// ---- the catch-up check every minute ----

test('a fresh catch-up check touches neither the lock nor the API', function () {
    markWeatherState(['last_attempt_at' => now()->subMinutes(10), 'last_success_at' => now()->subMinutes(10)]);
    fakeOpenMeteo(Http::response(openMeteoBody()));
    Cache::shouldReceive('lock')->never();

    expect(app(WeatherRefreshService::class)->catchUp())->toBeNull();

    Http::assertNothingSent();
});

test('sixty catch-up checks in a row after one refresh make exactly one request', function () {
    markWeatherState(['last_attempt_at' => now()->subHours(3), 'last_success_at' => now()->subHours(3)]);
    fakeOpenMeteo(Http::response(openMeteoBody()));

    foreach (range(1, 60) as $ignored) {
        app(WeatherRefreshService::class)->catchUp();
    }

    Http::assertSentCount(1);
    expect(SyncRun::where('trigger', 'catch_up')->count())->toBe(1);
});

test('the checked-again-under-the-lock rule: data that became fresh while waiting is not fetched again', function () {
    markWeatherState(['last_attempt_at' => now()->subHours(3), 'last_success_at' => now()->subHours(3)]);
    fakeOpenMeteo(Http::response(openMeteoBody()));
    $service = app(WeatherRefreshService::class);

    expect($service->needsCatchUp())->toBeTrue();      // the lock-free pre-check passes ...
    markWeatherState(['last_attempt_at' => now(), 'last_success_at' => now()]); // ... then another refresh finishes

    expect($service->catchUp())->toBeNull();

    Http::assertNothingSent();
});

// ---- one freshness policy ----

test('the shared freshness config holds the 60 and 180 minute limits', function () {
    expect(config('disaster_sources.sources.open_meteo.delayed_after_minutes'))->toBe(60)
        ->and(config('disaster_sources.sources.open_meteo.stale_after_minutes'))->toBe(180)
        ->and(config('disaster_sources.sources.open_meteo.catch_up_retry_minutes'))->toBe(30);
});

test('admin, public and catch-up agree on the weather age bands', function (int $minutes, string $admin, string $public, bool $warns, bool $catchUp) {
    markWeatherState(['last_status' => 'success', 'last_attempt_at' => now()->subMinutes($minutes), 'last_success_at' => now()->subMinutes($minutes)]);
    $health = app(SourceHealthService::class);

    $card = collect($health->adminSnapshot()['sources'])->firstWhere('key', 'open_meteo');
    $weather = $health->snapshot()['sources']['weather'];
    // The catch-up also waits for the retry window; look at the age rule alone.
    markWeatherState(['last_attempt_at' => now()->subHours(5)]);

    expect($card['status'])->toBe($admin)
        ->and($weather['status'])->toBe($public)
        ->and($weather['is_stale'])->toBe($warns)
        ->and(app(WeatherRefreshService::class)->needsCatchUp())->toBe($catchUp);
})->with([
    'fresh at 30 min' => [30, 'Healthy', 'Live', false, false],
    'delayed at 90 min' => [90, 'Delayed', 'Delayed', true, true],
    'delayed at 150 min' => [150, 'Delayed', 'Delayed', true, true],
    'stale at 240 min' => [240, 'Stale', 'Stale', true, true],
]);

test('changing the shared config moves the admin, public and catch-up limits together', function () {
    config(['disaster_sources.sources.open_meteo.delayed_after_minutes' => 20, 'disaster_sources.sources.open_meteo.stale_after_minutes' => 40]);
    markWeatherState(['last_status' => 'success', 'last_attempt_at' => now()->subHours(2), 'last_success_at' => now()->subMinutes(30)]);
    $health = app(SourceHealthService::class);

    expect(collect($health->adminSnapshot()['sources'])->firstWhere('key', 'open_meteo')['status'])->toBe('Delayed')
        ->and($health->snapshot()['sources']['weather']['status'])->toBe('Delayed')
        ->and(app(WeatherRefreshService::class)->needsCatchUp())->toBeTrue();
});

test('the public weather wording separates "may be out of date" from "stale"', function () {
    $source = file_get_contents(resource_path('js/components/disaster/WeatherMonitoring.vue'));

    expect($source)
        ->toContain("sourceMeta.status === 'Delayed'")
        ->toContain('Weather data may be out of date')
        ->toContain('data-test="weather-banner-delayed"')
        ->toContain('Serving Cached Weather Observations (Stale Data)')
        ->toContain('data-test="weather-banner-stale"');
});

// ---- partial responses ----

function partialBody(int $usable, string $time): array
{
    $body = openMeteoBody(40.0, $usable);
    foreach ($body as $index => $item) {
        $body[$index]['current']['time'] = $time;
    }

    return $body;
}

test('a full response is a normal success with no warning', function () {
    fakeOpenMeteo(Http::response(openMeteoBody()));

    $run = refreshWeather();

    expect($run->status)->toBe('success')->and($run->warning_message)->toBeNull()->and($run->fetched_count)->toBe(11);
});

test('a response with only one of eleven capitals updates that one, warns, and keeps the other ten', function () {
    Carbon\Carbon::setTestNow('2026-11-10 06:30:00');
    fakeOpenMeteo(Http::response(partialBody(11, '2026-11-10T12:00')));
    refreshWeather();
    $before = CapitalWeather::query()->orderBy('country_id')->get()->keyBy('country_id');
    $firstCountry = Country::query()->where('is_active', true)->orderBy('sort_order')->value('id');

    Carbon\Carbon::setTestNow('2026-11-10 06:31:00');
    fakeOpenMeteo(Http::response(partialBody(1, '2026-11-10T13:00')));
    $run = refreshWeather();

    expect($run->status)->toBe('success')
        ->and($run->warning_message)->toContain('usable observations for 1 of 11 capitals')
        ->and($run->fetched_count)->toBe(1)
        ->and($run->http_status)->toBe(200)
        ->and(DataSource::find('open_meteo')->last_status)->toBe('success');

    $after = CapitalWeather::query()->orderBy('country_id')->get()->keyBy('country_id');
    expect($after)->toHaveCount(11);

    foreach ($after as $countryId => $row) {
        if ($countryId === $firstCountry) {
            expect((float) $row->temperature_c)->toBe(40.1)
                ->and($row->observed_at->toIso8601String())->toBe('2026-11-10T06:00:00+00:00');

            continue;
        }

        // Untouched: same values and the same old observation time.
        expect($row->temperature_c)->toBe($before[$countryId]->temperature_c)
            ->and($row->observed_at->toIso8601String())->toBe('2026-11-10T05:00:00+00:00')
            ->and($row->fetched_at->toIso8601String())->toBe($before[$countryId]->fetched_at->toIso8601String());
    }

    Carbon\Carbon::setTestNow();
});

test('the Data Freshness read shows the partial run and which capitals are old', function () {
    Carbon\Carbon::setTestNow('2026-11-10 06:30:00');
    fakeOpenMeteo(Http::response(partialBody(11, '2026-11-10T12:00')));
    refreshWeather();
    Carbon\Carbon::setTestNow('2026-11-10 06:31:00');
    fakeOpenMeteo(Http::response(partialBody(1, '2026-11-10T13:00')));
    refreshWeather();

    $data = $this->actingAs(weatherAdmin())->getJson('/admin/data-sources')->assertOk()->json();
    $card = collect($data['sources'])->firstWhere('key', 'open_meteo');
    $latest = collect($data['runs'])->where('source_key', 'open_meteo')->first();

    expect($latest['state'])->toBe('success_with_warnings')
        ->and($latest['warning_message'])->toContain('usable observations for 1 of 11 capitals')
        ->and($card['latest_run']['state'])->toBe('success_with_warnings')
        ->and($card['status'])->toBe('Healthy')
        ->and($card['capitals_outdated'])->toBe(10)
        ->and($card['oldest_observation_at'])->toBe('2026-11-10T05:00:00+00:00');

    Carbon\Carbon::setTestNow();
});

test('a partial response still invalidates the cached dashboard when something changed', function () {
    fakeOpenMeteo(Http::response(partialBody(11, '2026-11-10T12:00')));
    refreshWeather();
    $generation = app(DashboardCache::class)->generation();

    fakeOpenMeteo(Http::response(partialBody(1, '2026-11-10T13:00')));
    refreshWeather();

    expect(app(DashboardCache::class)->generation())->not->toBe($generation);
});

test('the Freshness panel shows the oldest observation and how many capitals were not updated', function () {
    $source = file_get_contents(resource_path('js/components/admin/FreshnessPanel.vue'));

    expect($source)->toContain('Oldest observation')->toContain('Capitals not updated')->toContain('data-test="capitals-outdated"');
});

// ---- HTTP status recording ----

test('a genuine HTTP error records its status', function (int $status) {
    fakeOpenMeteo(Http::response('nope', $status));

    $run = refreshWeather();

    expect($run->status)->toBe('failed')->and($run->http_status)->toBe($status)->and($run->error_message)->toContain("HTTP {$status}");
})->with([503, 429, 404]);

test('a non-HTTP exception leaves the status empty even when its code looks like one', function () {
    fakeOpenMeteo(fn () => throw new RuntimeException('boom', 503));

    $run = refreshWeather();

    expect($run->status)->toBe('failed')
        ->and($run->http_status)->toBeNull()
        ->and($run->error_message)->toBe('boom');
});

test('a connection failure records no HTTP status', function () {
    fakeOpenMeteo(fn () => throw new ConnectionException('no route'));

    $run = refreshWeather();

    expect($run->status)->toBe('failed')->and($run->http_status)->toBeNull()->and($run->error_message)->toContain('Could not connect');
});

// ---- usable observations received versus database rows changed ----

test('11 usable observations with exactly one changed value is a full success', function () {
    Carbon\Carbon::setTestNow('2026-11-10 06:30:00');
    fakeOpenMeteo(Http::response(openMeteoBody()));
    refreshWeather();
    $before = CapitalWeather::query()->get()->keyBy('country_id');
    $changedCountry = Country::query()->where('is_active', true)->orderBy('sort_order')->value('id');

    Carbon\Carbon::setTestNow('2026-11-10 06:40:00');
    $body = openMeteoBody();
    $body[0]['current']['temperature_2m'] = 38.8; // only the first capital differs
    fakeOpenMeteo(Http::response($body));
    $run = refreshWeather();

    expect($run->status)->toBe('success')
        ->and($run->warning_message)->toBeNull()
        ->and($run->fetched_count)->toBe(11)
        ->and($run->updated_count)->toBe(1);

    foreach (CapitalWeather::query()->get() as $row) {
        // Every capital received a fresh observation, so every fetched_at moved.
        expect($row->fetched_at->toIso8601String())->toBe('2026-11-10T06:40:00+00:00');

        if ($row->country_id === $changedCountry) {
            expect((float) $row->temperature_c)->toBe(38.8);

            continue;
        }

        expect($row->temperature_c)->toBe($before[$row->country_id]->temperature_c)
            ->and($row->condition)->toBe($before[$row->country_id]->condition)
            ->and($row->observed_at->toIso8601String())->toBe($before[$row->country_id]->observed_at->toIso8601String());
    }

    Carbon\Carbon::setTestNow();
});

test('11 usable observations with no changed value is a full success with nothing updated', function () {
    fakeOpenMeteo(Http::response(openMeteoBody()));
    refreshWeather();

    $run = refreshWeather();

    expect($run->status)->toBe('success')
        ->and($run->warning_message)->toBeNull()
        ->and($run->fetched_count)->toBe(11)
        ->and($run->updated_count)->toBe(0);
});

test('one usable observation that changes nothing is still a partial response', function () {
    fakeOpenMeteo(Http::response(openMeteoBody()));
    refreshWeather();

    // Same values for the first capital, and no usable data for the other ten.
    fakeOpenMeteo(Http::response(openMeteoBody(30.4, 1)));
    $run = refreshWeather();

    expect($run->status)->toBe('success')
        ->and($run->warning_message)->toContain('usable observations for 1 of 11 capitals')
        ->and($run->fetched_count)->toBe(1)
        ->and($run->updated_count)->toBe(0)
        ->and(CapitalWeather::count())->toBe(11);
});
