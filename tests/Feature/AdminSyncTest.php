<?php

use App\Jobs\RunSourceSync;
use App\Models\DataSource;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\Disaster\Sync\DisasterSyncService;
use Database\Seeders\DataSourceSeeder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function () {
    (new DataSourceSeeder)->run();
    Sleep::fake();
});

function adminUser(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function fakeEmptyProviders(): void
{
    Http::fake([
        'earthquake.usgs.gov/*' => Http::response(['type' => 'FeatureCollection', 'features' => []]),
        'www.gdacs.org/*' => Http::response(['type' => 'FeatureCollection', 'features' => []]),
    ]);
}

function queuedRun(string $source = 'usgs', array $attributes = []): SyncRun
{
    return SyncRun::create($attributes + [
        'source_key' => $source, 'status' => 'queued', 'trigger' => 'manual', 'queued_at' => now(),
    ]);
}

// ---- access ----

test('only a verified admin can sync or read data sources', function () {
    Queue::fake();

    $this->postJson('/admin/data-sources/refresh', ['source' => 'usgs'])->assertUnauthorized();
    $this->getJson('/admin/data-sources')->assertUnauthorized();

    $user = User::factory()->create();
    $this->actingAs($user)->postJson('/admin/data-sources/refresh', ['source' => 'usgs'])->assertForbidden();
    $this->actingAs($user)->getJson('/admin/data-sources')->assertForbidden();

    $unverified = User::factory()->unverified()->create(['role' => 'admin']);
    $this->actingAs($unverified)->postJson('/admin/data-sources/refresh', ['source' => 'usgs'])->assertStatus(403);

    Queue::assertNothingPushed();
    expect(SyncRun::count())->toBe(0);
});

test('the source must be gdacs, usgs, open_meteo or all', function (string $source) {
    $this->actingAs(adminUser())->postJson('/admin/data-sources/refresh', ['source' => $source])->assertUnprocessable();
})->with(['weather', 'nasa', '']);

test('the request is throttled to 3 per minute per user', function () {
    Queue::fake();
    $admin = adminUser();

    foreach (range(1, 3) as $_) {
        $this->actingAs($admin)->postJson('/admin/data-sources/refresh', ['source' => 'usgs'])->assertStatus(202);
    }

    $this->actingAs($admin)->postJson('/admin/data-sources/refresh', ['source' => 'usgs'])->assertStatus(429);
});

// ---- the request ----

test('a request queues one job and one queued row and never contacts a provider', function () {
    Queue::fake();
    Http::preventStrayRequests();
    $admin = adminUser();

    $response = $this->actingAs($admin)->postJson('/admin/data-sources/refresh', ['source' => 'usgs'])->assertStatus(202);

    $run = SyncRun::sole();
    expect($response->json('results.usgs.state'))->toBe('queued')
        ->and($run->status)->toBe('queued')
        ->and($run->trigger)->toBe('manual')
        ->and($run->requested_by)->toBe($admin->id)
        ->and($run->started_at)->toBeNull()
        ->and($run->queued_at)->not->toBeNull()
        ->and($run->request_uuid)->toBe($response->json('request_uuid'));

    Queue::assertPushed(RunSourceSync::class, fn (RunSourceSync $job) => $job->source === 'usgs' && $job->runId === $run->id);
    Http::assertNothingSent();
});

test('"all" creates one row per source (GDACS, USGS and Open-Meteo) with the same request id', function () {
    Queue::fake();

    $response = $this->actingAs(adminUser())->postJson('/admin/data-sources/refresh', ['source' => 'all'])->assertStatus(202);

    $runs = SyncRun::orderBy('source_key')->get();
    expect($runs->pluck('source_key')->all())->toBe(['gdacs', 'open_meteo', 'usgs'])
        ->and($runs->pluck('request_uuid')->unique()->all())->toBe([$response->json('request_uuid')])
        ->and(array_keys($response->json('results')))->toBe(['gdacs', 'usgs', 'open_meteo']);

    Queue::assertPushed(RunSourceSync::class, 3);
});

test('a source that is already queued creates no second row or job', function () {
    Queue::fake();
    queuedRun('usgs');

    $response = $this->actingAs(adminUser())->postJson('/admin/data-sources/refresh', ['source' => 'usgs'])->assertStatus(202);

    expect($response->json('results.usgs.state'))->toBe('already_queued')
        ->and(SyncRun::count())->toBe(1);
    Queue::assertNothingPushed();
});

test('an expired queued row does not block a new request', function () {
    Queue::fake();
    queuedRun('usgs', ['queued_at' => now()->subMinutes(11)]);

    $this->actingAs(adminUser())->postJson('/admin/data-sources/refresh', ['source' => 'usgs'])
        ->assertJsonPath('results.usgs.state', 'queued');
});

test('a held source lock reports already_running and creates nothing', function () {
    Queue::fake();
    $lock = Cache::lock('sync:usgs', 300);
    $lock->get();

    $this->actingAs(adminUser())->postJson('/admin/data-sources/refresh', ['source' => 'usgs'])
        ->assertJsonPath('results.usgs.state', 'already_running');

    expect(SyncRun::count())->toBe(0);
    Queue::assertNothingPushed();
    $lock->release();
});

test('a sync that finished less than 60 seconds ago is in cooldown with the seconds left', function () {
    Queue::fake();
    SyncRun::create(['source_key' => 'usgs', 'status' => 'success', 'started_at' => now()->subSeconds(50), 'finished_at' => now()->subSeconds(40)]);

    $response = $this->actingAs(adminUser())->postJson('/admin/data-sources/refresh', ['source' => 'usgs']);

    expect($response->json('results.usgs.state'))->toBe('cooldown')
        ->and($response->json('results.usgs.seconds_left'))->toBeBetween(19, 21)
        ->and(SyncRun::count())->toBe(1);

    $this->travel(25)->seconds();
    $this->actingAs(adminUser())->postJson('/admin/data-sources/refresh', ['source' => 'usgs'])->assertJsonPath('results.usgs.state', 'queued');
});

test('one source can be in cooldown while the other is queued', function () {
    Queue::fake();
    SyncRun::create(['source_key' => 'gdacs', 'status' => 'success', 'started_at' => now()->subSeconds(20), 'finished_at' => now()->subSeconds(10)]);

    $results = $this->actingAs(adminUser())->postJson('/admin/data-sources/refresh', ['source' => 'all'])->json('results');

    expect($results['gdacs']['state'])->toBe('cooldown')->and($results['usgs']['state'])->toBe('queued');
});

// ---- the job ----

test('the job claims its own row, fetches, and finishes that same row', function () {
    fakeEmptyProviders();
    $admin = adminUser();
    $run = queuedRun('usgs', ['requested_by' => $admin->id]);

    (new RunSourceSync('usgs', $run->id))->handle(app(DisasterSyncService::class));

    $run->refresh();
    expect(SyncRun::count())->toBe(1)
        ->and($run->status)->toBe('success')
        ->and($run->trigger)->toBe('manual')
        ->and($run->requested_by)->toBe($admin->id)
        ->and($run->started_at)->not->toBeNull()
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->warning_message)->toBeNull()
        ->and(DataSource::find('usgs')->last_status)->toBe('success');

    Http::assertSentCount(1);
});

test('a failed fetch ends the claimed row as failed', function () {
    Http::fake(['earthquake.usgs.gov/*' => Http::response('down', 503)]);
    $run = queuedRun('usgs');

    (new RunSourceSync('usgs', $run->id))->handle(app(DisasterSyncService::class));

    expect($run->refresh()->status)->toBe('failed')
        ->and($run->error_message)->toContain('503')
        ->and(SyncRun::count())->toBe(1);
});

test('a job that cannot get the lock fetches nothing, deletes its row and leaves no running or failed row', function () {
    Http::preventStrayRequests();
    $lock = Cache::lock('sync:usgs', 300);
    $lock->get();
    $run = queuedRun('usgs');

    (new RunSourceSync('usgs', $run->id))->handle(app(DisasterSyncService::class));

    expect(SyncRun::count())->toBe(0)
        ->and(SyncRun::where('status', 'running')->exists())->toBeFalse();
    Http::assertNothingSent();
    $lock->release();
});

test('a job whose row was already failed by the sweep leaves it failed when the lock is busy', function () {
    $lock = Cache::lock('sync:usgs', 300);
    $lock->get();
    $run = queuedRun('usgs', ['status' => 'failed', 'error_message' => 'Not picked up by a worker.']);

    (new RunSourceSync('usgs', $run->id))->handle(app(DisasterSyncService::class));

    expect($run->refresh()->status)->toBe('failed');
    $lock->release();
});

test('an expired queued row is not run by a late worker and is marked failed', function () {
    Http::preventStrayRequests();
    $run = queuedRun('usgs', ['queued_at' => now()->subMinutes(11)]);

    (new RunSourceSync('usgs', $run->id))->handle(app(DisasterSyncService::class));

    expect($run->refresh()->status)->toBe('failed')
        ->and($run->error_message)->toBe('Not picked up by a worker.')
        ->and($run->started_at)->toBeNull();
    Http::assertNothingSent();
});

test('a row that was already claimed is not run again', function () {
    Http::preventStrayRequests();
    $run = queuedRun('usgs', ['status' => 'success', 'started_at' => now()->subMinute(), 'finished_at' => now()]);

    (new RunSourceSync('usgs', $run->id))->handle(app(DisasterSyncService::class));

    expect($run->refresh()->status)->toBe('success');
    Http::assertNothingSent();
});

test('the job settings match the HLD timing relations', function () {
    $job = new RunSourceSync('usgs', 1);

    expect($job->tries)->toBe(1)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and($job->timeout)->toBe(240)
        ->and($job->uniqueId())->toBe('usgs')
        ->and($job->uniqueFor)->toBeGreaterThanOrEqual((int) config('queue.connections.database.retry_after'))
        ->and((int) config('queue.connections.database.retry_after'))->toBeGreaterThan($job->timeout)
        ->and($job)->toBeInstanceOf(ShouldBeUnique::class);
});

// ---- sweep and warnings ----

test('the next sync marks an abandoned running row failed and resets the source status', function () {
    fakeEmptyProviders();
    $abandoned = SyncRun::create(['source_key' => 'usgs', 'status' => 'running', 'started_at' => now()->subMinutes(30)]);
    DataSource::where('key', 'usgs')->update(['last_status' => 'running']);

    $new = app(DisasterSyncService::class)->sync('usgs');

    expect($abandoned->refresh()->status)->toBe('failed')
        ->and($abandoned->error_message)->toContain('Abandoned')
        ->and($new->status)->toBe('success')
        ->and($new->id)->not->toBe($abandoned->id)
        ->and(DataSource::find('usgs')->last_status)->toBe('success');
});

test('the sweep never touches the other source or a run it is itself running', function () {
    fakeEmptyProviders();
    $other = SyncRun::create(['source_key' => 'gdacs', 'status' => 'running', 'started_at' => now()->subSeconds(5)]);
    $run = queuedRun('usgs');

    app(DisasterSyncService::class)->sync('usgs', $run->id);

    expect($other->refresh()->status)->toBe('running')
        ->and($run->refresh()->status)->toBe('success');
});

test('a GDACS run whose Green fetch fails is a success with a warning, not a failure', function () {
    Http::fake([
        'www.gdacs.org/*' => fn (Request $request) => $request['alertlevel'] === 'green'
            ? Http::response('boom', 500)
            : Http::response(['type' => 'FeatureCollection', 'features' => []]),
    ]);

    $run = app(DisasterSyncService::class)->sync('gdacs');

    $source = DataSource::find('gdacs');
    expect($run->status)->toBe('success')
        ->and($run->warning_message)->toContain('Green events could not be fetched')
        ->and($run->error_message)->toBeNull()
        ->and($source->last_status)->toBe('success')
        ->and($source->last_error)->toBeNull()
        ->and($source->consecutive_failures)->toBe(0)
        ->and($source->last_success_at)->not->toBeNull();
});

test('a GDACS run without problems has no warning', function () {
    fakeEmptyProviders();

    expect(app(DisasterSyncService::class)->sync('gdacs')->warning_message)->toBeNull();
});

// ---- the admin read ----

test('the data-sources read shows queued, running, warnings, abandoned and a missing worker', function () {
    $admin = adminUser();
    queuedRun('gdacs', ['queued_at' => now()->subMinutes(3)]);
    SyncRun::create(['source_key' => 'usgs', 'status' => 'running', 'started_at' => now()->subMinutes(10)]);

    $data = $this->actingAs($admin)->getJson('/admin/data-sources')->assertOk()->json();
    $cards = collect($data['sources'])->keyBy('key');

    expect($cards->keys()->all())->toBe(['usgs', 'gdacs', 'open_meteo'])
        ->and($cards['gdacs']['latest_run']['state'])->toBe('queued')
        ->and($cards['gdacs']['latest_run']['worker_warning'])->toBeTrue()
        ->and($cards['usgs']['latest_run']['state'])->toBe('abandoned')
        ->and($data['runs'])->toHaveCount(2);

    SyncRun::create(['source_key' => 'open_meteo', 'status' => 'success', 'trigger' => 'scheduler', 'started_at' => now()->subMinute(), 'finished_at' => now(), 'warning_message' => 'Green missing']);
    $weather = collect($this->actingAs($admin)->getJson('/admin/data-sources')->json('sources'))->firstWhere('key', 'open_meteo');
    expect($weather['latest_run']['state'])->toBe('success_with_warnings')
        ->and($weather['latest_run']['status'])->toBe('success');
});

test('a freshly queued run is not flagged as a missing worker', function () {
    queuedRun('usgs');

    $card = collect($this->actingAs(adminUser())->getJson('/admin/data-sources')->json('sources'))->firstWhere('key', 'usgs');

    expect($card['latest_run']['worker_warning'])->toBeFalse();
});

test('source status follows the HLD rules', function (array $state, string $expected) {
    DataSource::where('key', 'usgs')->update($state);

    $card = collect($this->actingAs(adminUser())->getJson('/admin/data-sources')->json('sources'))->firstWhere('key', 'usgs');

    expect($card['status'])->toBe($expected);
})->with([
    'never synced' => [[], 'Never Synced'],
    'healthy' => [fn () => ['last_attempt_at' => now(), 'last_success_at' => now()->subMinutes(3), 'last_status' => 'success'], 'Healthy'],
    'delayed' => [fn () => ['last_attempt_at' => now(), 'last_success_at' => now()->subMinutes(15), 'last_status' => 'success'], 'Delayed'],
    'stale' => [fn () => ['last_attempt_at' => now(), 'last_success_at' => now()->subMinutes(45), 'last_status' => 'success'], 'Stale'],
    'attempted, never succeeded' => [fn () => ['last_attempt_at' => now(), 'last_status' => 'success'], 'Stale'],
    'failed' => [fn () => ['last_attempt_at' => now(), 'last_success_at' => now()->subMinutes(3), 'last_status' => 'failed', 'consecutive_failures' => 2], 'Failed'],
    'failed repeatedly' => [fn () => ['last_attempt_at' => now(), 'last_success_at' => now()->subMinutes(3), 'last_status' => 'failed', 'consecutive_failures' => 3], 'Failed (repeated)'],
]);
