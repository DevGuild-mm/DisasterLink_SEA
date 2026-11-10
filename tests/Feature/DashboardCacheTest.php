<?php

use App\Models\Country;
use App\Models\DataSource;
use App\Models\DisasterEvent;
use App\Services\Disaster\Dashboard\DashboardCache;
use App\Services\Disaster\Dashboard\DashboardQueryService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
});

function dashboardCache(): DashboardCache
{
    return app(DashboardCache::class);
}

test('the first read builds from MySQL and the second is a cache hit', function () {
    $builds = 0;
    $build = function () use (&$builds): array {
        $builds++;

        return ['built' => $builds];
    };

    expect(dashboardCache()->remember($build))->toBe(['built' => 1])
        ->and(dashboardCache()->remember($build))->toBe(['built' => 1])
        ->and($builds)->toBe(1);
});

test('the payload is stored for 300 seconds and then rebuilt', function () {
    $builds = 0;
    $build = function () use (&$builds): array {
        return ['built' => ++$builds];
    };

    dashboardCache()->remember($build);
    $this->travel(299)->seconds();
    dashboardCache()->remember($build);
    expect($builds)->toBe(1);

    $this->travel(2)->seconds();
    dashboardCache()->remember($build);
    expect($builds)->toBe(2);
});

test('a read after an invalidation never serves the pre-sync payload', function () {
    dashboardCache()->remember(fn (): array => ['version' => 'before sync']);

    dashboardCache()->invalidateAfterSync();

    expect(dashboardCache()->remember(fn (): array => ['version' => 'after sync']))->toBe(['version' => 'after sync']);
});

test('a reader that started before the invalidation cannot overwrite the new generation', function () {
    $cache = dashboardCache();
    $generationAtStart = $cache->generation();

    // The slow reader is still building its payload from old data when the sync commits and invalidates.
    $cache->remember(function () use ($cache): array {
        $cache->invalidateAfterSync();

        return ['version' => 'old data'];
    });

    expect($cache->generation())->toBeGreaterThan($generationAtStart)
        ->and(Cache::get($cache->payloadKey($generationAtStart)))->toBe(['version' => 'old data'])
        ->and(Cache::get($cache->payloadKey()))->toBeNull()
        ->and($cache->remember(fn (): array => ['version' => 'new data']))->toBe(['version' => 'new data']);
});

test('invalidation forgets the country alert entries of the changed countries only', function () {
    Cache::put('country-alerts:1:v1', 'a', 60);
    Cache::put('country-alerts:2:v1', 'b', 60);
    Cache::put('country-alerts:3:v1', 'c', 60);

    dashboardCache()->invalidateAfterSync([1, 2, 2]);

    expect(Cache::has('country-alerts:1:v1'))->toBeFalse()
        ->and(Cache::has('country-alerts:2:v1'))->toBeFalse()
        ->and(Cache::get('country-alerts:3:v1'))->toBe('c');
});

test('reload rebuilds from MySQL at most once per 10 second window', function () {
    $builds = 0;
    $build = function () use (&$builds): array {
        return ['built' => ++$builds];
    };

    dashboardCache()->reload($build);
    dashboardCache()->reload($build);
    dashboardCache()->reload($build);
    expect($builds)->toBe(1);

    $this->travel(11)->seconds();
    dashboardCache()->reload($build);
    expect($builds)->toBe(2);
});

test('reload picks up rows stored since the cache was filled and never calls a provider', function () {
    seedDashboardData();
    markSourcesSynced();
    $queries = app(DashboardQueryService::class);

    expect($queries->dashboard()['events'])->toBeEmpty();

    DisasterEvent::factory()->create(['external_id' => 'FL:1', 'event_type' => 'flood', 'title' => 'New flood', 'occurred_at' => now()->subHour(), 'country_id' => Country::where('iso3', 'THA')->value('id'), 'country_status' => 'resolved']);

    expect($queries->dashboard()['events'])->toBeEmpty()
        ->and($queries->dashboard(reload: true)['events'])->toHaveCount(1);

    Http::assertNothingSent();
});

test('source health is read live: a no-change sync keeps data_as_of current while the cached payload stays', function () {
    seedDashboardData();
    markSourcesSynced();
    $queries = app(DashboardQueryService::class);

    $first = $queries->dashboard();

    $this->travel(2)->minutes();
    DataSource::whereIn('key', ['gdacs', 'usgs'])->update(['last_success_at' => now()]);

    $second = $queries->dashboard();

    expect($second['generated_at'])->toBe($first['generated_at'])
        ->and($second['data_as_of'])->not->toBe($first['data_as_of'])
        ->and($second['data_as_of'])->toBe(now()->toIso8601String());
});

test('a failing rebuild caches nothing and keeps the error visible', function () {
    expect(fn () => dashboardCache()->remember(fn (): array => throw new RuntimeException('database down')))
        ->toThrow(RuntimeException::class, 'database down')
        ->and(Cache::get(dashboardCache()->payloadKey()))->toBeNull();
});

test('an unavailable cache store falls back to building from MySQL', function () {
    Cache::shouldReceive('get')->andThrow(new RuntimeException('cache down'));

    expect(dashboardCache()->remember(fn (): array => ['from' => 'mysql']))->toBe(['from' => 'mysql']);
});

test('a lost generation counter only makes the next read rebuild', function () {
    dashboardCache()->invalidateAfterSync();
    dashboardCache()->remember(fn (): array => ['v' => 1]);

    Cache::flush();

    expect(dashboardCache()->remember(fn (): array => ['v' => 2]))->toBe(['v' => 2]);
});

test('the generation counter and the rebuild lock work on the configured store', function () {
    $before = dashboardCache()->generation();
    dashboardCache()->invalidateAfterSync();
    dashboardCache()->invalidateAfterSync();

    $lock = Cache::lock('dashboard:rebuild', 10);

    expect(dashboardCache()->generation())->toBe($before + 2)
        ->and($lock->get())->toBeTrue()
        ->and(Cache::lock('dashboard:rebuild', 10)->get())->toBeFalse();

    $lock->release();
});

test('Reload data over HTTP is limited to 6 per minute per visitor and the 7th is refused', function () {
    seedDashboardData();
    markSourcesSynced();

    foreach (range(1, 6) as $_) {
        $this->getJson('/api/disaster-dashboard?refresh=1')->assertOk();
    }

    $this->getJson('/api/disaster-dashboard?refresh=1')->assertStatus(429)->assertHeader('Retry-After');

    // Plain polling is not affected.
    $this->getJson('/api/disaster-dashboard')->assertOk();

    Http::assertNothingSent();
});

test('the page payload carries generated_at and data_as_of separately', function () {
    seedDashboardData();
    markSourcesSynced();

    $this->getJson('/api/disaster-dashboard')
        ->assertOk()
        ->assertJsonPath('data_as_of', now()->toIso8601String())
        ->assertJsonStructure(['generated_at', 'data_as_of']);
});
