<?php

use App\Jobs\ProcessEventAlerts;
use App\Jobs\SendDisasterAlertEmail;
use App\Mail\DisasterAlertMail;
use App\Models\Country;
use App\Models\DisasterAlertLog;
use App\Models\DisasterEvent;
use App\Models\User;
use App\Services\Disaster\Alerts\AlertEligibility;
use App\Services\Disaster\Sync\EarthquakeLinker;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;

beforeEach(function () {
    $this->travelTo('2026-11-10 12:00:00');
    $this->country = Country::factory()->create(['iso2' => 'ID', 'iso3' => 'IDN', 'name' => 'Indonesia', 'timezone' => 'Asia/Jakarta']);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function resolvedEvent(Country $country, array $attributes = []): DisasterEvent
{
    return DisasterEvent::factory()->create(array_merge([
        'country_id' => $country->id,
        'country_status' => 'resolved',
        'country_method' => 'source_code',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ], $attributes));
}

function evaluateAlerts(DisasterEvent ...$events): array
{
    return app(AlertEligibility::class)->evaluate(array_map(fn (DisasterEvent $e) => $e->id, $events), now());
}

/*
 * Eligibility (HLD §13.1 with the v1.3 recency rule).
 */

test('a new Orange or Red GDACS event within 24 hours becomes pending', function (string $level) {
    $event = resolvedEvent($this->country, ['alert_level' => $level, 'occurred_at' => now()->subHours(3)]);

    expect(evaluateAlerts($event))->toBe([$event->id])
        ->and($event->fresh()->alert_status)->toBe('pending')
        ->and($event->fresh()->threshold_met_at->equalTo(now()))->toBeTrue();
})->with(['Orange', 'Red']);

test('a Green GDACS event is not eligible', function () {
    $event = resolvedEvent($this->country, ['alert_level' => 'Green']);

    expect(evaluateAlerts($event))->toBe([])
        ->and($event->fresh()->threshold_met_at)->toBeNull();
});

test('a USGS earthquake needs magnitude 5.0 or more', function () {
    $low = resolvedEvent($this->country, ['source' => 'usgs', 'external_id' => 'us-low', 'event_type' => 'earthquake', 'magnitude' => 4.9, 'alert_level' => 'Orange']);
    $edge = resolvedEvent($this->country, ['source' => 'usgs', 'external_id' => 'us-edge', 'event_type' => 'earthquake', 'magnitude' => 5.0]);

    expect(evaluateAlerts($low, $edge))->toBe([$edge->id]);
});

test('an event stored below the threshold that escalates later alerts, even if it began days ago', function () {
    $event = resolvedEvent($this->country, [
        'alert_level' => 'Orange',
        'occurred_at' => now()->subDays(3),
        'first_seen_at' => now()->subDays(3),
    ]);

    expect(evaluateAlerts($event))->toBe([$event->id]);
});

test('an old event first seen already above the threshold never alerts', function () {
    $event = resolvedEvent($this->country, ['alert_level' => 'Red', 'occurred_at' => now()->subDays(3)]);

    expect(evaluateAlerts($event))->toBe([])
        ->and($event->fresh()->threshold_met_at)->not->toBeNull();

    $this->travel(1)->hours();

    expect(evaluateAlerts($event->fresh()))->toBe([]);
});

test('an escalation seen more than 24 hours after it happened does not alert', function () {
    $event = resolvedEvent($this->country, [
        'alert_level' => 'Orange',
        'occurred_at' => now()->subDays(5),
        'first_seen_at' => now()->subDays(5),
        'threshold_met_at' => now()->subDays(2),
    ]);

    expect(evaluateAlerts($event))->toBe([]);
});

test('a USGS magnitude revised from 4.9 to 5.0 becomes eligible', function () {
    $event = resolvedEvent($this->country, [
        'source' => 'usgs', 'external_id' => 'us-rev', 'event_type' => 'earthquake', 'magnitude' => 4.9,
        'occurred_at' => now()->subHours(30), 'first_seen_at' => now()->subHours(29),
    ]);

    expect(evaluateAlerts($event))->toBe([]);

    $event->update(['magnitude' => 5.0]);

    expect(evaluateAlerts($event->fresh()))->toBe([$event->id]);
});

test('threshold_met_at is set once and never overwritten', function () {
    $first = now()->subMinutes(20)->startOfSecond();
    $event = resolvedEvent($this->country, [
        'alert_level' => 'Orange', 'alert_status' => 'completed', 'threshold_met_at' => $first, 'occurred_at' => now()->subHour(),
    ]);

    evaluateAlerts($event);

    expect($event->fresh()->threshold_met_at->equalTo($first))->toBeTrue()
        ->and($event->fresh()->alert_status)->toBe('completed');
});

test('an event without a resolved country waits, and alerts once the country is known', function () {
    $event = DisasterEvent::factory()->gdacsAlert('Orange')->create(['occurred_at' => now()->subHour(), 'first_seen_at' => now()]);

    expect(evaluateAlerts($event))->toBe([])
        ->and($event->fresh()->alert_status)->toBe('not_eligible');

    $event->forceFill(['country_id' => $this->country->id, 'country_status' => 'resolved', 'country_method' => 'adm0_lookup'])->save();

    expect(evaluateAlerts($event->fresh()))->toBe([$event->id]);
});

test('synthetic ids and linked-away rows never alert', function () {
    $synthetic = resolvedEvent($this->country, ['alert_level' => 'Red', 'source_meta' => ['synthetic_id' => true]]);
    $canonical = resolvedEvent($this->country, ['alert_level' => 'Green']);
    $child = resolvedEvent($this->country, ['alert_level' => 'Red', 'canonical_event_id' => $canonical->id]);

    expect(evaluateAlerts($synthetic, $child))->toBe([])
        ->and($synthetic->fresh()->threshold_met_at)->toBeNull();
});

test('a linked GDACS Orange makes a smaller USGS canonical earthquake eligible', function () {
    $usgs = resolvedEvent($this->country, [
        'source' => 'usgs', 'external_id' => 'us-group', 'event_type' => 'earthquake', 'magnitude' => 4.8,
        'first_seen_at' => now()->subMinutes(15), 'occurred_at' => now()->subMinutes(20),
    ]);

    expect(evaluateAlerts($usgs))->toBe([]);

    resolvedEvent($this->country, [
        'source' => 'gdacs', 'external_id' => 'EQ:1', 'event_type' => 'earthquake', 'magnitude' => 5.1, 'alert_level' => 'Orange',
        'canonical_event_id' => $usgs->id,
    ]);

    expect(evaluateAlerts($usgs->fresh()))->toBe([$usgs->id]);
});

/*
 * Earthquake linking (HLD §11.5) and the linked-source country (HLD §27.4).
 */

function quake(string $source, array $attributes = []): DisasterEvent
{
    return DisasterEvent::factory()->create(array_merge([
        'source' => $source,
        'external_id' => $source === 'usgs' ? 'us'.fake()->unique()->numerify('######') : 'EQ:'.fake()->unique()->numerify('######'),
        'event_type' => 'earthquake',
        'magnitude' => 5.5,
        'latitude' => -1.0,
        'longitude' => 100.0,
        'occurred_at' => now()->subMinutes(30),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ], $attributes));
}

test('the same earthquake from both sources is linked, and the first seen stays canonical', function () {
    $usgs = quake('usgs', ['first_seen_at' => now()->subMinutes(10)]);
    $gdacs = quake('gdacs', ['occurred_at' => now()->subMinutes(28), 'latitude' => -1.2, 'longitude' => 100.1, 'magnitude' => 5.7]);

    $result = app(EarthquakeLinker::class)->link([$gdacs->id]);

    expect($gdacs->fresh()->canonical_event_id)->toBe($usgs->id)
        ->and($usgs->fresh()->canonical_event_id)->toBeNull()
        ->and($result['canonical'])->toBe([$usgs->id])
        ->and($result['changes'])->toBe(1);
});

test('earthquakes outside the time, distance or magnitude limits are not linked', function (array $overrides) {
    $usgs = quake('usgs');
    $gdacs = quake('gdacs', $overrides);

    app(EarthquakeLinker::class)->link([$gdacs->id]);

    expect($gdacs->fresh()->canonical_event_id)->toBeNull();
})->with([
    'six minutes apart' => [['occurred_at' => now()->subMinutes(36)]],
    'about 55 km apart' => [['latitude' => -1.5, 'longitude' => 100.2]],
    'magnitude differs by 0.6' => [['magnitude' => 6.1]],
    'no coordinates' => [['latitude' => null, 'longitude' => null]],
]);

test('only earthquakes are linked and a group never chains', function () {
    $flood = resolvedEvent($this->country, ['external_id' => 'FL:1', 'event_type' => 'flood']);
    $otherFlood = resolvedEvent($this->country, ['source' => 'usgs', 'external_id' => 'x1', 'event_type' => 'flood', 'latitude' => $flood->latitude, 'longitude' => $flood->longitude, 'occurred_at' => $flood->occurred_at]);

    app(EarthquakeLinker::class)->link([$otherFlood->id]);
    expect($otherFlood->fresh()->canonical_event_id)->toBeNull();

    $usgs = quake('usgs', ['first_seen_at' => now()->subMinutes(20)]);
    $gdacs = quake('gdacs', ['first_seen_at' => now()->subMinutes(10)]);
    $extra = quake('gdacs', ['first_seen_at' => now()]);

    app(EarthquakeLinker::class)->link([$gdacs->id, $extra->id]);

    expect($gdacs->fresh()->canonical_event_id)->toBe($usgs->id)
        ->and($extra->fresh()->canonical_event_id)->toBeNull();
});

test('an offshore USGS earthquake takes the country of its linked GDACS record', function () {
    $usgs = quake('usgs', ['first_seen_at' => now()->subMinutes(10), 'country_status' => 'unresolved']);
    $gdacs = quake('gdacs', [
        'country_id' => $this->country->id, 'country_status' => 'resolved', 'country_method' => 'source_code',
    ]);

    app(EarthquakeLinker::class)->link([$gdacs->id]);

    $usgs->refresh();

    expect($usgs->country_id)->toBe($this->country->id)
        ->and($usgs->country_status)->toBe('resolved')
        ->and($usgs->country_method)->toBe('linked_source');
});

/*
 * ProcessEventAlerts.
 */

function pendingEvent(Country $country): DisasterEvent
{
    return resolvedEvent($country, ['alert_level' => 'Orange', 'alert_status' => 'pending', 'threshold_met_at' => now()]);
}

test('alert logs are created only for verified opted-in users of the event country, admins included', function () {
    Bus::fake([SendDisasterAlertEmail::class]);
    $other = Country::factory()->create();

    $user = User::factory()->create(['country_id' => $this->country->id]);
    $admin = User::factory()->admin()->create(['country_id' => $this->country->id]);
    User::factory()->unverified()->create(['country_id' => $this->country->id]);
    User::factory()->alertsDisabled()->create(['country_id' => $this->country->id]);
    User::factory()->create(['country_id' => $other->id]);
    User::factory()->create(['country_id' => null]);
    $event = pendingEvent($this->country);

    (new ProcessEventAlerts($event->id))->handle();

    expect(DisasterAlertLog::pluck('user_id')->sort()->values()->all())->toBe([$user->id, $admin->id])
        ->and(DisasterAlertLog::where('status', 'pending')->count())->toBe(2)
        ->and($event->fresh()->alert_status)->toBe('completed')
        ->and($event->fresh()->alerts_completed_at)->not->toBeNull();

    Bus::assertDispatchedTimes(SendDisasterAlertEmail::class, 2);
});

test('running ProcessEventAlerts again creates no second log and does nothing', function () {
    Bus::fake([SendDisasterAlertEmail::class]);
    User::factory()->create(['country_id' => $this->country->id]);
    $event = pendingEvent($this->country);

    (new ProcessEventAlerts($event->id))->handle();
    (new ProcessEventAlerts($event->id))->handle();

    expect(DisasterAlertLog::count())->toBe(1);
    Bus::assertDispatchedTimes(SendDisasterAlertEmail::class, 1);
});

test('a resumed run covers logs that were created before a crash', function () {
    Bus::fake([SendDisasterAlertEmail::class]);
    $first = User::factory()->create(['country_id' => $this->country->id]);
    $second = User::factory()->create(['country_id' => $this->country->id]);
    $event = pendingEvent($this->country);
    DisasterAlertLog::factory()->create(['user_id' => $first->id, 'disaster_event_id' => $event->id]);

    (new ProcessEventAlerts($event->id))->handle();

    expect(DisasterAlertLog::count())->toBe(2)->and($second->alertLogs()->count())->toBe(1);
    Bus::assertDispatchedTimes(SendDisasterAlertEmail::class, 2);
});

test('ProcessEventAlerts ignores events that are not pending or are linked away', function () {
    Bus::fake([SendDisasterAlertEmail::class]);
    User::factory()->create(['country_id' => $this->country->id]);
    $completed = resolvedEvent($this->country, ['alert_status' => 'completed']);
    $child = resolvedEvent($this->country, ['alert_status' => 'pending', 'canonical_event_id' => $completed->id]);

    (new ProcessEventAlerts($completed->id))->handle();
    (new ProcessEventAlerts($child->id))->handle();

    expect(DisasterAlertLog::count())->toBe(0);
});

/*
 * SendDisasterAlertEmail.
 */

function pendingLog(Country $country, array $user = [], array $event = []): DisasterAlertLog
{
    return DisasterAlertLog::factory()->create([
        'user_id' => User::factory()->create(array_merge(['country_id' => $country->id], $user))->id,
        'disaster_event_id' => resolvedEvent($country, array_merge(['alert_level' => 'Orange', 'alert_status' => 'completed'], $event))->id,
    ]);
}

test('dry run suppresses the alert and sends nothing', function () {
    Mail::fake();
    config(['alerts.dry_run' => true]);
    $log = pendingLog($this->country);

    (new SendDisasterAlertEmail($log->id))->handle();

    expect($log->fresh()->status)->toBe('suppressed')
        ->and($log->fresh()->suppression_reason)->toBe('dry_run')
        ->and($log->fresh()->sent_at)->toBeNull();
    Mail::assertNothingSent();
});

test('an alert is sent once and marked sent, a repeat does nothing', function () {
    Mail::fake();
    config(['alerts.dry_run' => false]);
    $log = pendingLog($this->country);

    (new SendDisasterAlertEmail($log->id))->handle();
    (new SendDisasterAlertEmail($log->id))->handle();

    expect($log->fresh()->status)->toBe('sent')
        ->and($log->fresh()->attempts)->toBe(1)
        ->and($log->fresh()->sent_at->equalTo(now()))->toBeTrue();
    Mail::assertSentCount(1);
    Mail::assertSent(DisasterAlertMail::class, fn (DisasterAlertMail $mail) => $mail->hasTo($log->user->email));
});

test('a user who opted out or lost verification before sending is suppressed', function (array $user) {
    Mail::fake();
    config(['alerts.dry_run' => false]);
    $log = pendingLog($this->country, $user);

    (new SendDisasterAlertEmail($log->id))->handle();

    expect($log->fresh()->status)->toBe('suppressed')->and($log->fresh()->suppression_reason)->toBe('not_eligible');
    Mail::assertNothingSent();
})->with([
    'alerts off' => [['alerts_enabled' => false]],
    'unverified' => [['email_verified_at' => null]],
]);

test('the daily limit defers the alert until the next UTC day without using up an attempt', function () {
    Mail::fake();
    config(['alerts.dry_run' => false, 'alerts.daily_limit' => 2]);
    DisasterAlertLog::factory()->sent()->count(2)->create();
    $log = pendingLog($this->country);

    (new SendDisasterAlertEmail($log->id))->handle();

    $log->refresh();

    expect($log->status)->toBe('deferred')
        ->and($log->failure_type)->toBe('quota')
        ->and($log->attempts)->toBe(0)
        ->and($log->next_attempt_at->toDateTimeString())->toBe('2026-11-11 00:00:00');
    Mail::assertNothingSent();
});

test('the monthly limit defers the alert to the first of next month', function () {
    Mail::fake();
    config(['alerts.dry_run' => false, 'alerts.monthly_limit' => 1, 'alerts.daily_limit' => 100]);
    DisasterAlertLog::factory()->sent()->create(['sent_at' => now()->subDays(3)]);
    $log = pendingLog($this->country);

    (new SendDisasterAlertEmail($log->id))->handle();

    expect($log->fresh()->status)->toBe('deferred')->and($log->fresh()->next_attempt_at->toDateTimeString())->toBe('2026-12-01 00:00:00');
});

test('a deferred alert is sent once its time has come, and not before', function () {
    Mail::fake();
    config(['alerts.dry_run' => false]);
    $log = pendingLog($this->country);
    $log->update(['status' => 'deferred', 'next_attempt_at' => now()->addMinutes(5), 'attempts' => 1]);

    (new SendDisasterAlertEmail($log->id))->handle();
    expect($log->fresh()->status)->toBe('deferred');

    $this->travel(6)->minutes();
    (new SendDisasterAlertEmail($log->id))->handle();

    expect($log->fresh()->status)->toBe('sent')->and($log->fresh()->attempts)->toBe(2);
});

test('a transport failure is retried with backoff and fails after the last attempt', function () {
    config(['alerts.dry_run' => false]);
    Mail::shouldReceive('to->send')->andThrow(new TransportException('connection refused'));
    $log = pendingLog($this->country);

    foreach ([1, 5, 15, 60] as $attempt => $minutes) {
        (new SendDisasterAlertEmail($log->id))->handle();

        $fresh = $log->fresh();
        expect($fresh->status)->toBe('deferred')
            ->and($fresh->attempts)->toBe($attempt + 1)
            ->and($fresh->failure_type)->toBe('transient')
            ->and($fresh->next_attempt_at->equalTo(now()->addMinutes($minutes)))->toBeTrue();

        $this->travel($minutes + 1)->minutes();
    }

    (new SendDisasterAlertEmail($log->id))->handle();

    expect($log->fresh()->status)->toBe('failed')->and($log->fresh()->attempts)->toBe(5)->and($log->fresh()->failure_type)->toBe('transient');
});

test('any other exception is a permanent failure with no retry', function () {
    config(['alerts.dry_run' => false]);
    Mail::shouldReceive('to->send')->andThrow(new RuntimeException('bad recipient'));
    $log = pendingLog($this->country);

    (new SendDisasterAlertEmail($log->id))->handle();
    $this->travel(2)->days();
    (new SendDisasterAlertEmail($log->id))->handle();

    expect($log->fresh()->status)->toBe('failed')->and($log->fresh()->failure_type)->toBe('permanent')->and($log->fresh()->attempts)->toBe(1);
});

/*
 * The email itself (HLD §13.8).
 */

test('the email is a country-level alert and never claims the user area is affected', function () {
    $user = User::factory()->create(['country_id' => $this->country->id]);
    $event = resolvedEvent($this->country, [
        'source' => 'usgs', 'external_id' => 'us1', 'event_type' => 'earthquake', 'title' => 'M 5.6 - 45 km SSW of Padang',
        'magnitude' => 5.6, 'location_text' => '45 km SSW of Padang, Indonesia', 'latitude' => -1.2, 'longitude' => 100.1,
        'occurred_at' => now()->subHour(), 'source_url' => 'https://earthquake.usgs.gov/earthquakes/eventpage/us1',
    ]);

    $mail = new DisasterAlertMail($event, $user);
    $html = $mail->render();

    expect($mail->envelope()->subject)->toBe('[DisasterLink SEA] Earthquake reported in Indonesia: M 5.6')
        ->and($html)->toContain('45 km SSW of Padang, Indonesia')
        ->toContain('Indonesia')
        ->toContain('-1.200, 100.100')
        ->toContain('2026-11-10 18:00 (Asia/Jakarta)')
        ->toContain('2026-11-10 11:00 UTC')
        ->toContain('USGS')
        ->toContain('https://earthquake.usgs.gov/earthquakes/eventpage/us1')
        ->toContain('It does not mean your city or area is directly affected')
        ->toContain('/preparedness?country=ID#contacts')
        ->toContain('/preparedness#guides')
        ->not->toContain('/emergency-contacts')
        ->not->toContain('/safety-guides')
        ->not->toContain('Delayed delivery');
});

test('a late alert carries the delayed delivery note', function () {
    $user = User::factory()->create(['country_id' => $this->country->id]);
    $event = resolvedEvent($this->country, ['alert_level' => 'Red', 'occurred_at' => now()->subHours(60)]);

    expect((new DisasterAlertMail($event, $user, true))->render())->toContain('Delayed delivery')
        ->and((new DisasterAlertMail($event, $user, true))->envelope()->subject)->toContain('Red');
});

test('an event older than 48 hours is sent with the delayed note', function () {
    Mail::fake();
    config(['alerts.dry_run' => false]);
    $log = pendingLog($this->country, event: ['occurred_at' => now()->subHours(50)]);

    (new SendDisasterAlertEmail($log->id))->handle();

    Mail::assertSent(DisasterAlertMail::class, fn (DisasterAlertMail $mail) => $mail->delayed === true);
});

/*
 * alerts:dispatch recovery (HLD v1.3 §27.5).
 */

test('alerts:dispatch re-queues pending events, due emails and fails stuck sends', function () {
    Bus::fake([ProcessEventAlerts::class, SendDisasterAlertEmail::class]);

    $stale = resolvedEvent($this->country, ['alert_status' => 'pending', 'threshold_met_at' => now()->subMinutes(10)]);
    $fresh = resolvedEvent($this->country, ['alert_status' => 'pending', 'threshold_met_at' => now()->subMinute()]);

    $oldPending = DisasterAlertLog::factory()->create(['created_at' => now()->subMinutes(10)]);
    $newPending = DisasterAlertLog::factory()->create(['created_at' => now()->subMinute()]);
    $due = DisasterAlertLog::factory()->create(['status' => 'deferred', 'next_attempt_at' => now()->subMinute()]);
    $notDue = DisasterAlertLog::factory()->create(['status' => 'deferred', 'next_attempt_at' => now()->addHour()]);
    $stuck = DisasterAlertLog::factory()->create(['status' => 'sending', 'last_attempt_at' => now()->subMinutes(20)]);
    $sending = DisasterAlertLog::factory()->create(['status' => 'sending', 'last_attempt_at' => now()->subMinutes(2)]);

    $this->artisan('alerts:dispatch')->assertSuccessful();

    Bus::assertDispatched(ProcessEventAlerts::class, fn ($job) => $job->eventId === $stale->id);
    Bus::assertNotDispatched(ProcessEventAlerts::class, fn ($job) => $job->eventId === $fresh->id);
    Bus::assertDispatched(SendDisasterAlertEmail::class, fn ($job) => $job->logId === $oldPending->id);
    Bus::assertDispatched(SendDisasterAlertEmail::class, fn ($job) => $job->logId === $due->id);
    Bus::assertNotDispatched(SendDisasterAlertEmail::class, fn ($job) => in_array($job->logId, [$newPending->id, $notDue->id, $sending->id, $stuck->id], true));

    expect($stuck->fresh()->status)->toBe('failed')->and($stuck->fresh()->failure_type)->toBe('uncertain')
        ->and($sending->fresh()->status)->toBe('sending');
});

test('alerts:dispatch queues the oldest events first', function () {
    Bus::fake([SendDisasterAlertEmail::class]);
    $newer = DisasterAlertLog::factory()->create([
        'created_at' => now()->subMinutes(10),
        'disaster_event_id' => DisasterEvent::factory()->create(['occurred_at' => now()->subHour()])->id,
    ]);
    $older = DisasterAlertLog::factory()->create([
        'created_at' => now()->subMinutes(10),
        'disaster_event_id' => DisasterEvent::factory()->create(['occurred_at' => now()->subHours(5)])->id,
    ]);

    $this->artisan('alerts:dispatch')->assertSuccessful();

    $order = [];
    Bus::assertDispatched(SendDisasterAlertEmail::class, function ($job) use (&$order) {
        $order[] = $job->logId;

        return true;
    });

    expect($order)->toBe([$older->id, $newer->id]);
});

test('alerts:dispatch is scheduled every five minutes without overlapping', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'alerts:dispatch'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

/*
 * Settings and registration feed the alert recipients (Phase 7.1).
 */

test('a user who switches alerts off in Settings is no longer a recipient', function () {
    Bus::fake([SendDisasterAlertEmail::class]);
    $user = User::factory()->create(['country_id' => $this->country->id]);
    $stays = User::factory()->create(['country_id' => $this->country->id]);

    $this->actingAs($user)->patch(route('profile.update'), ['name' => $user->name, 'email' => $user->email, 'alerts_enabled' => '0']);

    $event = pendingEvent($this->country);
    (new ProcessEventAlerts($event->id))->handle();

    expect(DisasterAlertLog::pluck('user_id')->all())->toBe([$stays->id]);
});

test('switching alerts off after an alert is queued stops that email too', function () {
    Mail::fake();
    $user = User::factory()->create(['country_id' => $this->country->id]);
    $event = pendingEvent($this->country);
    $log = DisasterAlertLog::factory()->create(['user_id' => $user->id, 'disaster_event_id' => $event->id, 'status' => 'pending']);

    $this->actingAs($user)->patch(route('profile.update'), ['name' => $user->name, 'email' => $user->email, 'alerts_enabled' => '0']);

    (new SendDisasterAlertEmail($log->id))->handle();

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

test('changing the country in Settings moves the user to the new country\'s alerts', function () {
    Bus::fake([SendDisasterAlertEmail::class]);
    $vietnam = Country::factory()->create(['iso2' => 'VN', 'iso3' => 'VNM', 'name' => 'Vietnam']);
    $user = User::factory()->create(['country_id' => $this->country->id]);

    $this->actingAs($user)->patch(route('profile.update'), ['name' => $user->name, 'email' => $user->email, 'country_id' => $vietnam->id]);

    $indonesia = pendingEvent($this->country);
    (new ProcessEventAlerts($indonesia->id))->handle();
    expect(DisasterAlertLog::count())->toBe(0);

    $vietnamEvent = pendingEvent($vietnam);
    (new ProcessEventAlerts($vietnamEvent->id))->handle();
    expect(DisasterAlertLog::pluck('user_id')->all())->toBe([$user->id]);
});

test('a user who registers after an event was processed is not back-filled', function () {
    Bus::fake([SendDisasterAlertEmail::class]);
    $event = pendingEvent($this->country);
    (new ProcessEventAlerts($event->id))->handle();

    $this->post(route('register.store'), [
        'name' => 'Late User', 'email' => 'late@example.com', 'password' => 'password', 'password_confirmation' => 'password',
        'country_id' => $this->country->id,
    ])->assertSessionHasNoErrors();

    expect(DisasterAlertLog::count())->toBe(0);
});

test('a newly registered user gets no alert until the email is verified', function () {
    Bus::fake([SendDisasterAlertEmail::class]);

    $this->post(route('register.store'), [
        'name' => 'New User', 'email' => 'new@example.com', 'password' => 'password', 'password_confirmation' => 'password',
        'country_id' => $this->country->id,
    ])->assertSessionHasNoErrors();

    $event = pendingEvent($this->country);
    (new ProcessEventAlerts($event->id))->handle();

    expect(DisasterAlertLog::count())->toBe(0);
});
