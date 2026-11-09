<?php

use App\Models\CapitalWeather;
use App\Models\Country;
use App\Models\CountryBoundary;
use App\Models\DataSource;
use App\Models\DisasterAlertLog;
use App\Models\DisasterEvent;
use App\Models\EmergencyContact;
use App\Models\SafetyGuide;
use App\Models\SyncRun;
use App\Models\User;
use Database\Seeders\CountrySeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DataSourceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

test('every HLD table and new user column exists', function () {
    foreach ([
        'countries', 'country_boundaries', 'disaster_events', 'disaster_alert_logs',
        'capital_weather', 'data_sources', 'sync_runs', 'safety_guides', 'emergency_contacts',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing table {$table}");
    }

    expect(Schema::hasColumns('users', ['role', 'country_id', 'alerts_enabled']))->toBeTrue()
        ->and(Schema::hasColumn('disaster_events', 'threshold_met_at'))->toBeTrue()
        ->and(Schema::hasColumn('disaster_events', 'alert_lease_until'))->toBeFalse();
});

test('country seeder creates the 11 ASEAN countries and is idempotent', function () {
    $this->seed(CountrySeeder::class);
    $this->seed(CountrySeeder::class);

    expect(Country::count())->toBe(11)
        ->and(Country::where('iso3', 'IDN')->value('timezone'))->toBe('Asia/Jakarta')
        ->and(Country::where('iso2', 'TL')->value('name'))->toBe('Timor-Leste');
});

test('data source seeder keeps existing health fields', function () {
    $this->seed(DataSourceSeeder::class);
    DataSource::find('gdacs')->update(['last_status' => 'success', 'consecutive_failures' => 0]);

    $this->seed(DataSourceSeeder::class);

    expect(DataSource::count())->toBe(3)
        ->and(DataSource::pluck('key')->sort()->values()->all())->toBe(['gdacs', 'open_meteo', 'usgs'])
        ->and(DataSource::find('gdacs')->last_status)->toBe('success')
        ->and(DataSource::find('open_meteo')->interval_minutes)->toBe(30);
});

test('database seeder creates a verified local admin and a normal user', function () {
    $this->seed(DatabaseSeeder::class);

    $admin = User::where('email', 'admin@example.com')->first();
    $user = User::where('email', 'test@example.com')->first();

    expect($admin->isAdmin())->toBeTrue()
        ->and($admin->hasVerifiedEmail())->toBeTrue()
        ->and($user->isAdmin())->toBeFalse()
        ->and($user->country->iso2)->toBe('TH')
        ->and($user->alerts_enabled)->toBeTrue();
});

test('a disaster event is unique per source and external id', function () {
    DisasterEvent::factory()->create(['source' => 'gdacs', 'external_id' => 'FL:1']);

    DisasterEvent::factory()->create(['source' => 'usgs', 'external_id' => 'FL:1']);

    expect(fn () => DisasterEvent::factory()->create(['source' => 'gdacs', 'external_id' => 'FL:1']))
        ->toThrow(QueryException::class);
});

test('upsert updates an event in place instead of duplicating it', function () {
    $row = fn (string $title) => [
        'source' => 'gdacs', 'external_id' => 'TC:9', 'event_type' => 'cyclone', 'title' => $title,
        'occurred_at' => now(), 'first_seen_at' => now(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ];

    DisasterEvent::upsert([$row('First')], ['source', 'external_id'], ['title']);
    DisasterEvent::upsert([$row('Second')], ['source', 'external_id'], ['title']);

    expect(DisasterEvent::count())->toBe(1)
        ->and(DisasterEvent::first()->title)->toBe('Second');
});

test('an alert log is unique per user and event', function () {
    $user = User::factory()->create();
    $event = DisasterEvent::factory()->create();

    DisasterAlertLog::factory()->create(['user_id' => $user->id, 'disaster_event_id' => $event->id]);

    expect(fn () => DisasterAlertLog::factory()->create(['user_id' => $user->id, 'disaster_event_id' => $event->id]))
        ->toThrow(QueryException::class);

    expect(DisasterAlertLog::insertOrIgnore([
        'user_id' => $user->id, 'disaster_event_id' => $event->id, 'status' => 'pending',
        'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]))->toBe(0);
});

test('event defaults and casts match the HLD', function () {
    $event = DisasterEvent::factory()->usgsEarthquake(5.4)->create(['source_meta' => ['iso3' => 'IDN']]);
    $event = $event->fresh();

    expect($event->country_status)->toBe('unresolved')
        ->and($event->alert_status)->toBe('not_eligible')
        ->and($event->country_id)->toBeNull()
        ->and($event->threshold_met_at)->toBeNull()
        ->and($event->source_meta)->toBe(['iso3' => 'IDN'])
        ->and($event->occurred_at)->toBeInstanceOf(DateTimeInterface::class);
});

test('a duplicate event points at its canonical event', function () {
    $canonical = DisasterEvent::factory()->usgsEarthquake()->create();
    $duplicate = DisasterEvent::factory()->create(['canonical_event_id' => $canonical->id]);

    expect($duplicate->canonicalEvent->is($canonical))->toBeTrue()
        ->and($canonical->duplicates)->toHaveCount(1);
});

test('country relationships work', function () {
    $country = Country::factory()->create();
    CountryBoundary::factory()->create(['country_id' => $country->id]);
    CapitalWeather::factory()->create(['country_id' => $country->id]);
    $user = User::factory()->create(['country_id' => $country->id]);
    DisasterEvent::factory()->create(['country_id' => $country->id]);
    EmergencyContact::factory()->create(['country_id' => $country->id]);
    SafetyGuide::factory()->create(['country_id' => $country->id]);

    $country->refresh();

    expect($country->boundary->country_id)->toBe($country->id)
        ->and($country->capitalWeather->country_id)->toBe($country->id)
        ->and($country->users)->toHaveCount(1)
        ->and($country->disasterEvents)->toHaveCount(1)
        ->and($country->emergencyContacts)->toHaveCount(1)
        ->and($country->safetyGuides)->toHaveCount(1)
        ->and($user->country->is($country))->toBeTrue();
});

test('a country that has users cannot be deleted', function () {
    $country = Country::factory()->create();
    User::factory()->create(['country_id' => $country->id]);

    expect(fn () => $country->delete())->toThrow(QueryException::class);
});

test('deleting a user removes their alert logs', function () {
    $log = DisasterAlertLog::factory()->create();

    $log->user->delete();

    expect(DisasterAlertLog::count())->toBe(0);
});

test('sync runs belong to a data source', function () {
    $source = DataSource::factory()->create(['key' => 'gdacs']);
    SyncRun::factory()->count(2)->create(['source_key' => 'gdacs']);

    expect($source->syncRuns)->toHaveCount(2)
        ->and($source->syncRuns->first()->dataSource->key)->toBe('gdacs');
});

test('safety guides support all-country scope, slugs and the published scope', function () {
    SafetyGuide::factory()->create(['slug' => 'flood-safety', 'country_id' => null]);
    SafetyGuide::factory()->draft()->create();

    expect(SafetyGuide::published()->count())->toBe(1)
        ->and(SafetyGuide::first()->country)->toBeNull()
        ->and(fn () => SafetyGuide::factory()->create(['slug' => 'flood-safety']))->toThrow(QueryException::class);
});

test('emergency contacts require a country and support the published scope', function () {
    EmergencyContact::factory()->create();
    EmergencyContact::factory()->draft()->create();

    expect(EmergencyContact::published()->count())->toBe(1)
        ->and(fn () => EmergencyContact::factory()->create(['country_id' => null]))->toThrow(QueryException::class);
});

test('users default to alerts on and no country, and the factory admin state works', function () {
    $user = User::factory()->create()->fresh();
    $admin = User::factory()->admin()->create();

    expect($user->alerts_enabled)->toBeTrue()
        ->and($user->country_id)->toBeNull()
        ->and($admin->isAdmin())->toBeTrue()
        ->and(User::factory()->alertsDisabled()->create()->fresh()->alerts_enabled)->toBeFalse();
});
