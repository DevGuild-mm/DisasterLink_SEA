<?php

use App\Models\Country;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('home', absolute: false));
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));

    $this->assertGuest();
});

test('users are rate limited', function () {
    $user = User::factory()->create();

    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertTooManyRequests();
});

test('an administrator lands on /admin after logging in', function () {
    $admin = User::factory()->admin()->create();

    $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])
        ->assertRedirect('/admin');

    $this->assertAuthenticatedAs($admin);
});

test('a regular user lands on the Welcome page after logging in', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('home', absolute: false));
});

test('a login goes back to the page the person was trying to reach', function () {
    $user = User::factory()->create();

    $this->get('/settings/profile')->assertRedirect(route('login'));

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/settings/profile');
});

test('an administrator is sent back to the admin page they were trying to reach', function () {
    $admin = User::factory()->admin()->create();

    $this->get('/admin')->assertRedirect(route('login'));

    $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])
        ->assertRedirect('/admin');
});

test('a regular user who tries /admin is refused, not redirected there', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin')->assertForbidden();
});

// ---- which page a login ends on: the intended URL wins, the role only picks the fallback ----

test('an administrator who was heading to another page goes back there, not to /admin', function () {
    $admin = User::factory()->admin()->create();

    $this->get('/settings/profile')->assertRedirect(route('login'));

    $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])
        ->assertRedirect('/settings/profile');
});

test('an administrator with no intended page lands on /admin, wherever they came from', function () {
    $admin = User::factory()->admin()->create();

    // Opening the public pages and the login form first does not create an intended page.
    $this->get('/preparedness')->assertOk();
    $this->get(route('login'))->assertOk();

    $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])->assertRedirect('/admin');
});

test('a regular user who was heading to a page goes back there, with its query string', function () {
    $user = User::factory()->create();

    $this->get('/settings/profile?tab=alerts')->assertRedirect(route('login'));

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/settings/profile?tab=alerts');
});

test('a regular user with no intended page lands on the Welcome page', function () {
    $user = User::factory()->create();

    $this->get('/preparedness')->assertOk();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('home', absolute: false));
});

test('the intended page is used once; the next login falls back to the role default', function () {
    $admin = User::factory()->admin()->create();

    $this->get('/settings/profile');
    $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])->assertRedirect('/settings/profile');

    $this->post(route('logout'));
    $this->assertGuest();

    $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])->assertRedirect('/admin');
});

test('a failed login keeps the intended page for the next attempt', function () {
    $user = User::factory()->create();

    $this->get('/settings/profile');
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect('/settings/profile');
});

test('a protected POST by a guest returns to the page it came from, not to the POST address', function () {
    $user = User::factory()->create();

    $this->from('/preparedness')->post('/admin/safety-guides')->assertRedirect(route('login'));

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/preparedness');
});

test('registration also returns to the intended page, otherwise the Welcome page', function () {
    $country = DB::table('countries')->insertGetId([
        'iso2' => 'TH', 'iso3' => 'THA', 'name' => 'Thailand', 'timezone' => 'Asia/Bangkok', 'capital_name' => 'Bangkok',
        'capital_lat' => 13.75, 'capital_lng' => 100.5, 'sort_order' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $payload = ['name' => 'New', 'password' => 'password', 'password_confirmation' => 'password', 'country_id' => $country];

    $this->get('/settings/profile');
    $this->post(route('register.store'), $payload + ['email' => 'one@example.com'])->assertRedirect('/settings/profile');

    $this->post(route('logout'));
    $this->post(route('register.store'), $payload + ['email' => 'two@example.com'])->assertRedirect(route('home', absolute: false));
});

// ---- Option B: a non-administrator is never sent back to an /admin page ----

/**
 * The URL Laravel would have saved for a protected page: the application's own address plus the path as requested.
 */
function intendedUrl(string $path): string
{
    return rtrim(url('/'), '/').$path;
}

/**
 * Intended URLs that point at the admin area, in the forms a browser or an attacker could produce.
 *
 * @return array<string, array{string}>
 */
function adminAreaUrls(): array
{
    return [
        '/admin' => ['/admin'],
        '/admin/' => ['/admin/'],
        'nested' => ['/admin/data-sources'],
        'deeply nested' => ['/admin/safety-guides/12/edit'],
        'with a query string' => ['/admin?tab=guides'],
        'nested with a query string' => ['/admin/data-sources?x=1'],
        'with a fragment' => ['/admin#top'],
        'upper case' => ['/ADMIN'],
        'mixed case nested' => ['/Admin/Data-Sources'],
        'percent-encoded letter' => ['/%61dmin'],
        'percent-encoded slash' => ['/admin%2Fdata-sources'],
        'double slash prefix' => ['//admin'],
        'double slash inside' => ['/admin//data-sources'],
    ];
}

/**
 * Intended URLs that only look like the admin area.
 *
 * @return array<string, array{string}>
 */
function nonAdminAreaUrls(): array
{
    return [
        'settings' => ['/settings/profile'],
        'settings with a query string' => ['/settings/profile?tab=alerts'],
        'preparedness filters' => ['/preparedness?country=TH&hazard=flood'],
        'preparedness with an admin word in the query' => ['/preparedness?hazard=admin'],
        'preparedness with an admin fragment' => ['/preparedness#admin'],
        'a path that starts with the letters' => ['/administrator'],
        'a path with a dash' => ['/admin-tools'],
        'admin deeper in the path' => ['/settings/admin'],
        'an api path' => ['/api/admin'],
    ];
}

test('a regular user whose intended page is the admin area lands on the Welcome page, not a 403', function (string $url) {
    $user = User::factory()->create();

    $this->withSession(['url.intended' => intendedUrl($url)])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/');
})->with(adminAreaUrls());

test('a regular user\'s unusable intended page is used up, so it cannot come back later', function () {
    $user = User::factory()->create();

    $this->withSession(['url.intended' => 'http://localhost/admin/data-sources'])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/');

    expect(session()->has('url.intended'))->toBeFalse();
});

test('a regular user still returns to an ordinary intended page, query string included', function (string $url) {
    $user = User::factory()->create();

    $this->withSession(['url.intended' => intendedUrl($url)])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect($url);
})->with(nonAdminAreaUrls());

test('an administrator may return to any intended page, including the admin area', function (string $url) {
    $admin = User::factory()->admin()->create();

    $this->withSession(['url.intended' => intendedUrl($url)])
        ->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])
        ->assertRedirect(intendedUrl($url));
})->with([...adminAreaUrls(), ...nonAdminAreaUrls()]);

test('an administrator with no intended page lands on /admin', function () {
    $admin = User::factory()->admin()->create();

    $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])->assertRedirect('/admin');
});

test('a real guest visit to a nested admin page sends a regular user to the Welcome page after login', function () {
    $user = User::factory()->create();

    $this->get('/admin/data-sources')->assertRedirect(route('login'));

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect('/');
});

test('a real guest visit to a nested admin page takes an administrator back there after login', function () {
    $admin = User::factory()->admin()->create();

    $this->get('/admin/data-sources')->assertRedirect(route('login'));

    $this->post(route('login.store'), ['email' => $admin->email, 'password' => 'password'])->assertRedirect('/admin/data-sources');
});

test('the admin area itself is still forbidden to a regular user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/admin')->assertForbidden();
    $this->actingAs($user)->get('/admin/data-sources')->assertForbidden();
});

test('registration ignores an intended admin page and lands on the Welcome page', function (string $url) {
    $country = Country::factory()->create();

    $this->withSession(['url.intended' => intendedUrl($url)])
        ->post(route('register.store'), [
            'name' => 'New', 'email' => 'new@example.com', 'password' => 'password', 'password_confirmation' => 'password',
            'country_id' => $country->id,
        ])->assertRedirect('/');

    $this->assertAuthenticated();
    expect(User::where('email', 'new@example.com')->sole()->isAdmin())->toBeFalse();
})->with(adminAreaUrls());

test('registration returns to an ordinary intended page, query string included', function (string $url) {
    $country = Country::factory()->create();

    $this->withSession(['url.intended' => intendedUrl($url)])
        ->post(route('register.store'), [
            'name' => 'New', 'email' => 'new@example.com', 'password' => 'password', 'password_confirmation' => 'password',
            'country_id' => $country->id,
        ])->assertRedirect($url);
})->with(nonAdminAreaUrls());

test('login and registration still answer JSON requests with JSON', function () {
    $user = User::factory()->create();
    $country = Country::factory()->create();

    $this->postJson(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertOk()->assertJson(['two_factor' => false]);

    $this->post(route('logout'));

    $this->postJson(route('register.store'), [
        'name' => 'Json', 'email' => 'json@example.com', 'password' => 'password', 'password_confirmation' => 'password',
        'country_id' => $country->id,
    ])->assertCreated();
});
