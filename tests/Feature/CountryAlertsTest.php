<?php

use App\Models\Country;
use App\Models\DisasterEvent;
use App\Models\User;
use App\Services\Disaster\Dashboard\CountryAlertsQuery;
use App\Services\Disaster\Dashboard\DashboardCache;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Cache::flush();
    $this->country = Country::factory()->create(['iso2' => 'TH', 'iso3' => 'THA', 'name' => 'Thailand']);
});

/**
 * An alert-eligible event of the country whose threshold was met `$metHoursAgo` hours ago.
 *
 * @param  array<string, mixed>  $attributes
 */
function alertEvent(Country $country, float $metHoursAgo = 1, array $attributes = []): DisasterEvent
{
    return DisasterEvent::factory()->gdacsAlert('Orange')->create($attributes + [
        'country_id' => $country->id,
        'country_status' => 'resolved',
        'alert_status' => 'completed',
        'threshold_met_at' => now()->subMinutes((int) ($metHoursAgo * 60)),
        'occurred_at' => now()->subMinutes((int) ($metHoursAgo * 60)),
        'title' => 'Alert '.fake()->unique()->numberBetween(1, 99999),
    ]);
}

function countryAlerts(Country $country): array
{
    return app(CountryAlertsQuery::class)->forCountry($country->id);
}

// ---- the query ----

test('only alert-eligible, canonical, resolved events of the country are listed', function () {
    $other = Country::factory()->create();
    $shown = alertEvent($this->country);
    alertEvent($this->country, 1, ['alert_status' => 'pending']);
    alertEvent($this->country, 1, ['alert_status' => 'not_eligible']);
    alertEvent($this->country, 1, ['country_status' => 'unresolved']);
    alertEvent($this->country, 1, ['canonical_event_id' => $shown->id]);
    alertEvent($other);

    $alerts = countryAlerts($this->country);

    expect($alerts)->toHaveCount(2)
        ->and(collect($alerts)->pluck('id')->all())->toContain($shown->source.'_'.$shown->external_id);
});

test('the window is seven days counted from when the threshold was met, not from when the event occurred', function () {
    $recentAlertOldEvent = alertEvent($this->country, 24, ['occurred_at' => now()->subDays(20)]);
    alertEvent($this->country, 24 * 8, ['occurred_at' => now()->subHour()]);
    $insideEdge = alertEvent($this->country, 24 * 6.9);

    $ids = collect(countryAlerts($this->country))->pluck('id');

    expect($ids)->toHaveCount(2)
        ->and($ids)->toContain($recentAlertOldEvent->source.'_'.$recentAlertOldEvent->external_id, $insideEdge->source.'_'.$insideEdge->external_id);
});

test('alerts are newest first and limited to ten', function () {
    foreach (range(1, 12) as $i) {
        alertEvent($this->country, $i);
    }

    $alerts = countryAlerts($this->country);
    $times = collect($alerts)->pluck('alerted_at')->all();

    expect($alerts)->toHaveCount(10)
        ->and($times)->toBe(collect($times)->sortDesc()->values()->all());
});

test('a row carries only what the page shows', function () {
    alertEvent($this->country, 1, [
        'event_type' => 'flood', 'location_text' => 'Chao Phraya basin', 'source_url' => 'https://www.gdacs.org/report.aspx?eventid=1',
        'raw_payload' => ['secret' => 'x'], 'latitude' => 13.7, 'longitude' => 100.5,
    ]);

    $row = countryAlerts($this->country)[0];

    expect(array_keys($row))->toBe(['id', 'type', 'title', 'location', 'severity', 'severity_text', 'source', 'source_url', 'alerted_at', 'occurred_at'])
        ->and($row['type'])->toBe('Flood')
        ->and($row['location'])->toBe('Chao Phraya basin')
        ->and($row['severity'])->toBe('Orange alert')
        ->and($row['source'])->toBe('GDACS')
        ->and($row['source_url'])->toBe('https://www.gdacs.org/report.aspx?eventid=1');
});

test('a USGS earthquake shows its magnitude as the severity', function () {
    DisasterEvent::factory()->usgsEarthquake(6.1)->create([
        'country_id' => $this->country->id, 'country_status' => 'resolved', 'alert_status' => 'completed', 'threshold_met_at' => now()->subHour(),
    ]);

    expect(countryAlerts($this->country)[0]['severity'])->toBe('M 6.1')
        ->and(countryAlerts($this->country)[0]['type'])->toBe('Earthquake');
});

test('a source link that is not http or https is dropped', function (?string $url) {
    alertEvent($this->country, 1, ['source_url' => $url]);

    expect(countryAlerts($this->country)[0]['source_url'])->toBeNull();
})->with([null, 'javascript:alert(1)', 'data:text/html,x', 'ftp://example.org/x']);

// ---- the cache ----

test('the result is cached for 60 seconds per country and forgotten after a relevant change', function () {
    alertEvent($this->country);
    expect(countryAlerts($this->country))->toHaveCount(1);
    expect(Cache::has('country-alerts:'.$this->country->id.':v1'))->toBeTrue();

    alertEvent($this->country, 2);
    expect(countryAlerts($this->country))->toHaveCount(1);          // still the cached answer

    app(DashboardCache::class)->invalidateAfterSync([$this->country->id]);
    expect(countryAlerts($this->country))->toHaveCount(2);          // forgotten, rebuilt

    alertEvent($this->country, 3);
    $this->travel(61)->seconds();
    expect(countryAlerts($this->country))->toHaveCount(3);          // expired after 60 s
});

test('the cache is separate for each country', function () {
    $other = Country::factory()->create();
    alertEvent($this->country);

    expect(countryAlerts($this->country))->toHaveCount(1)->and(countryAlerts($other))->toHaveCount(0);
});

// ---- the Welcome page ----

test('a guest gets no alert data, only the promotion', function () {
    alertEvent($this->country, 1, ['title' => 'Distinct Guest Hidden Title']);

    // The event itself is public information elsewhere on the page; the personal alerts prop is simply absent.
    $this->get('/')->assertOk()->assertInertia(fn ($page) => $page->where('countryAlerts', null));
});

test('a signed-in user sees the alerts of their own country only', function () {
    $other = Country::factory()->create(['name' => 'Elsewhere']);
    alertEvent($this->country, 1, ['title' => 'Alert in my country']);
    alertEvent($other, 1, ['title' => 'Alert in another country']);
    $user = User::factory()->create(['country_id' => $this->country->id]);

    $this->actingAs($user)->get('/')->assertOk()->assertInertia(fn ($page) => $page
        ->where('countryAlerts.country.name', 'Thailand')
        ->where('countryAlerts.window_days', 7)
        ->has('countryAlerts.alerts', 1)
        ->where('countryAlerts.alerts.0.title', 'Alert in my country'));
});

test('an unverified user and an admin see the same list', function (string $kind) {
    alertEvent($this->country);
    $user = match ($kind) {
        'unverified' => User::factory()->unverified()->create(['country_id' => $this->country->id]),
        'admin' => User::factory()->admin()->create(['country_id' => $this->country->id]),
    };

    $this->actingAs($user)->get('/')->assertInertia(fn ($page) => $page
        ->has('countryAlerts.alerts', 1)
        ->where('auth.user.email_verified_at', $kind === 'unverified' ? null : $user->email_verified_at->toJSON()));
})->with(['unverified', 'admin']);

test('a user without a country, or with a deactivated one, gets the set-a-country prompt data', function (bool $inactive) {
    alertEvent($this->country);
    $country = $inactive ? Country::factory()->create(['is_active' => false]) : null;
    $user = User::factory()->create(['country_id' => $country?->id]);

    $this->actingAs($user)->get('/')->assertInertia(fn ($page) => $page
        ->where('countryAlerts.country', null)
        ->has('countryAlerts.alerts', 0));
})->with([false, true]);

test('a signed-in user in a country with no recent alerts gets an empty list', function () {
    $user = User::factory()->create(['country_id' => $this->country->id]);
    alertEvent($this->country, 24 * 9);

    $this->actingAs($user)->get('/')->assertInertia(fn ($page) => $page->has('countryAlerts.alerts', 0));
});

// ---- the component ----

test('the component carries the approved wording for guests, users, unverified users and users without a country', function () {
    $source = file_get_contents(resource_path('js/components/disaster/CountryAlerts.vue'));

    expect($source)
        ->toContain('Register your country to receive email alerts for')
        ->toContain('significant disasters reported in that country.')
        ->toContain('does not mean every city or area')
        ->toContain('Guests keep full access to public information.')
        ->toContain('Create free account')
        ->toContain('data-test="country-alerts-login"')
        ->toContain('Recent alerts for {{ countryAlerts.country.name }}')
        ->toContain('Alerts are country-level. Check the event location and')
        ->toContain('official sources to see whether your area is affected.')
        ->toContain('Verify your email to receive alert emails.')
        ->toContain('Resend the verification email.')
        ->toContain('Set your country in')
        ->toContain('rel="noopener noreferrer"')
        ->toContain('Original source');

    expect(file_get_contents(resource_path('js/pages/Welcome.vue')))
        ->toContain('<CountryAlerts :country-alerts="countryAlerts" />');
});

// ---- the verification confirmation on the Welcome page ----

test('after resending the verification email the Welcome page shows the confirmation status', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create(['country_id' => $this->country->id]);

    $this->actingAs($user)->from('/')->post(route('verification.send'))->assertRedirect('/');

    $this->actingAs($user)->get('/')->assertOk()->assertInertia(fn ($page) => $page->where('status', 'verification-link-sent'));
    expect(file_get_contents(resource_path('js/components/disaster/CountryAlerts.vue')))
        ->toContain("page.props.status === 'verification-link-sent'")
        ->toContain('A new verification link has been sent.');
});

test('the Welcome page has no status without a resend, and the flash is shown once', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create(['country_id' => $this->country->id]);

    $this->actingAs($user)->get('/')->assertInertia(fn ($page) => $page->where('status', null));

    $this->actingAs($user)->from('/')->post(route('verification.send'));
    $this->actingAs($user)->get('/')->assertInertia(fn ($page) => $page->where('status', 'verification-link-sent'));
    $this->actingAs($user)->get('/')->assertInertia(fn ($page) => $page->where('status', null));
});

// ---- Alerted versus Occurred ----

test('an alert row labels the alert time "Alerted" and the event time "Occurred" separately', function () {
    $event = alertEvent($this->country, 1, ['occurred_at' => now()->subDays(20)]);

    $row = countryAlerts($this->country)[0];
    $source = file_get_contents(resource_path('js/components/disaster/CountryAlerts.vue'));

    // The two times differ: the alert time is when the threshold was met, not when the event occurred.
    expect($row['alerted_at'])->not->toBe($row['occurred_at'])
        ->and($row['occurred_at'])->toBe($event->occurred_at->toIso8601String());

    expect($source)
        ->toContain('data-test="alert-alerted"')
        ->toContain('Alerted:')
        ->toContain('formatTime(alert.alerted_at)')
        ->toContain('data-test="alert-occurred"')
        ->toContain('Occurred:')
        ->toContain('formatTime(alert.occurred_at)');
});

test('the occurrence line is omitted when there is no occurrence time', function () {
    $source = file_get_contents(resource_path('js/components/disaster/CountryAlerts.vue'));

    expect($source)
        ->toContain('v-if="alert.occurred_at"')
        ->toContain('occurred_at: string | null;')
        ->toContain('v-if="alert.alerted_at"');
});
