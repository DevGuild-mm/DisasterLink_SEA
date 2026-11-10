<?php

use App\Models\Country;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
    $this->thailand = Country::factory()->create(['iso2' => 'TH', 'iso3' => 'THA', 'name' => 'Thailand']);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function registration(array $overrides = []): array
{
    return $overrides + [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'country_id' => Country::where('iso2', 'TH')->value('id'),
    ];
}

test('registration screen can be rendered with the active countries', function () {
    Country::factory()->create(['iso2' => 'VN', 'iso3' => 'VNM', 'name' => 'Vietnam', 'sort_order' => 2]);
    Country::factory()->create(['iso2' => 'LA', 'iso3' => 'LAO', 'name' => 'Laos', 'is_active' => false]);

    $this->get(route('register'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('auth/Register')
        ->has('countries', 2)
        ->where('countries.0.iso2', 'TH')
        ->where('countries.1.iso2', 'VN'));
});

test('new users can register and land on the Welcome page', function () {
    $response = $this->post(route('register.store'), registration());

    $this->assertAuthenticated();
    $response->assertRedirect(route('home', absolute: false));
});

test('a new user is saved with the chosen country, alerts on by default, no role and no verified email', function () {
    $this->post(route('register.store'), registration());

    $user = User::where('email', 'test@example.com')->sole();

    expect($user->country_id)->toBe($this->thailand->id)
        ->and($user->alerts_enabled)->toBeTrue()
        ->and($user->role)->toBe('user')
        ->and($user->isAdmin())->toBeFalse()
        ->and($user->email_verified_at)->toBeNull();
});

test('the alerts preference chosen at registration is saved', function (mixed $value, bool $expected) {
    $this->post(route('register.store'), registration(['alerts_enabled' => $value]))->assertSessionHasNoErrors();

    expect(User::where('email', 'test@example.com')->sole()->alerts_enabled)->toBe($expected);
})->with([
    'off as 0' => [0, false],
    'off as "0"' => ['0', false],
    'on as 1' => [1, true],
    'on as "1"' => ['1', true],
    'on as true' => [true, true],
]);

test('registration needs a country', function () {
    $this->post(route('register.store'), registration(['country_id' => null]))
        ->assertSessionHasErrors(['country_id' => 'Please choose your country.']);

    $this->post(route('register.store'), collect(registration())->except('country_id')->all())
        ->assertSessionHasErrors('country_id');

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

test('registration rejects an unknown, inactive or malformed country', function (mixed $country) {
    $value = $country === 'inactive' ? Country::factory()->create(['is_active' => false])->id : $country;

    $this->post(route('register.store'), registration(['country_id' => $value]))->assertSessionHasErrors('country_id');

    $this->assertGuest();
    expect(User::count())->toBe(0);
})->with([
    'unknown id' => [999999],
    'inactive country' => ['inactive'],
    'text' => ['Thailand'],
    'array' => [[1]],
    'negative' => [-1],
]);

test('registration rejects an invalid alerts preference', function () {
    $this->post(route('register.store'), registration(['alerts_enabled' => 'maybe']))->assertSessionHasErrors('alerts_enabled');

    expect(User::count())->toBe(0);
});

test('extra input cannot make a new user an administrator or verified', function () {
    $this->post(route('register.store'), registration([
        'role' => 'admin',
        'is_admin' => true,
        'email_verified_at' => now()->toDateTimeString(),
        'id' => 9999,
    ]));

    $user = User::where('email', 'test@example.com')->sole();

    expect($user->role)->toBe('user')
        ->and($user->isAdmin())->toBeFalse()
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->id)->not->toBe(9999);
});

test('registration still validates name, email and password', function (string $field, mixed $value) {
    $this->post(route('register.store'), registration([$field => $value]))->assertSessionHasErrors($field);

    expect(User::count())->toBe(0);
})->with([
    'no name' => ['name', ''],
    'bad email' => ['email', 'not-an-email'],
    'short password' => ['password', '123'],
]);

test('registration refuses a duplicate email', function () {
    User::factory()->create(['email' => 'test@example.com']);

    $this->post(route('register.store'), registration())->assertSessionHasErrors('email');
});

test('a verification email is sent, and no alert goes out before the email is verified', function () {
    Notification::fake();

    $this->post(route('register.store'), registration());

    $user = User::where('email', 'test@example.com')->sole();
    Notification::assertSentTo($user, VerifyEmail::class);
    expect($user->hasVerifiedEmail())->toBeFalse();
});

// ---- rate limit ----

test('registration is limited to 6 attempts a minute per address, and the 7th is refused', function () {
    foreach (range(1, 6) as $i) {
        // Invalid on purpose: every attempt counts, successful or not.
        $this->post(route('register.store'), registration(['email' => 'not-an-email']))->assertSessionHasErrors('email');
    }

    $this->post(route('register.store'), registration())->assertStatus(429);

    expect(User::count())->toBe(0);
});

test('the registration limit lifts after a minute', function () {
    foreach (range(1, 6) as $i) {
        $this->post(route('register.store'), registration(['email' => 'not-an-email']));
    }

    $this->post(route('register.store'), registration())->assertStatus(429);

    $this->travel(61)->seconds();

    $this->post(route('register.store'), registration())->assertSessionHasNoErrors();
    expect(User::count())->toBe(1);
});

test('the registration limit is per address and does not touch other pages', function () {
    foreach (range(1, 6) as $i) {
        $this->post(route('register.store'), registration(['email' => 'not-an-email']));
    }

    $this->post(route('register.store'), registration())->assertStatus(429);

    // Viewing the forms is not counted, and a different address is not limited.
    $this->get(route('register'))->assertOk();
    $this->get(route('login'))->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->post(route('register.store'), registration())->assertSessionHasNoErrors();
    expect(User::count())->toBe(1);
});
