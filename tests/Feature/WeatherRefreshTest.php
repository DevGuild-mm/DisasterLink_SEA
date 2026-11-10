<?php

use App\Models\CapitalWeather;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\SyncRun;
use App\Services\Disaster\Dashboard\DashboardCache;
use App\Services\Disaster\Weather\WeatherRefreshService;
use Database\Seeders\CountrySeeder;
use Database\Seeders\DataSourceSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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
