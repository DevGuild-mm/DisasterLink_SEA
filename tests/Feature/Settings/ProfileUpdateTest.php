<?php

use App\Models\Country;
use App\Models\User;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())->not->toBeNull();
});

// ---- country and the alerts preference (Phase 7.1) ----

test('the profile page offers the active countries', function () {
    Country::factory()->create(['iso2' => 'TH', 'name' => 'Thailand', 'sort_order' => 1]);
    Country::factory()->create(['iso2' => 'LA', 'name' => 'Laos', 'is_active' => false]);

    $this->actingAs(User::factory()->create())->get(route('profile.edit'))
        ->assertInertia(fn ($page) => $page->component('settings/Profile')->has('countries', 1)->where('countries.0.iso2', 'TH'));
});

test('a user can set their country and the alerts preference', function () {
    $thailand = Country::factory()->create();
    $user = User::factory()->create(['country_id' => null]);

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => $user->email,
        'country_id' => $thailand->id,
        'alerts_enabled' => false,
    ])->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));

    $user->refresh();
    expect($user->country_id)->toBe($thailand->id)->and($user->alerts_enabled)->toBeFalse();

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name, 'email' => $user->email, 'country_id' => $thailand->id, 'alerts_enabled' => '1',
    ]);

    expect($user->refresh()->alerts_enabled)->toBeTrue();
});

test('a user without a country can update their name and email without choosing one', function () {
    $user = User::factory()->create(['country_id' => null, 'alerts_enabled' => true]);

    $this->actingAs($user)->patch(route('profile.update'), ['name' => 'New Name', 'email' => $user->email])
        ->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->name)->toBe('New Name')->and($user->country_id)->toBeNull()->and($user->alerts_enabled)->toBeTrue();
});

test('leaving the country and alerts fields out of the request leaves them unchanged', function () {
    $country = Country::factory()->create();
    $user = User::factory()->alertsDisabled()->create(['country_id' => $country->id]);

    $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Same Settings', 'email' => $user->email])
        ->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->country_id)->toBe($country->id)->and($user->alerts_enabled)->toBeFalse();
});

test('an empty country field clears the country', function () {
    $user = User::factory()->create(['country_id' => Country::factory()->create()->id]);

    $this->actingAs($user)->patch(route('profile.update'), ['name' => $user->name, 'email' => $user->email, 'country_id' => ''])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->country_id)->toBeNull();
});

test('a user cannot choose an unknown, inactive or malformed country', function (mixed $country) {
    $original = Country::factory()->create();
    $user = User::factory()->create(['country_id' => $original->id]);
    $value = $country === 'inactive' ? Country::factory()->create(['is_active' => false])->id : $country;

    $this->actingAs($user)->patch(route('profile.update'), ['name' => $user->name, 'email' => $user->email, 'country_id' => $value])
        ->assertSessionHasErrors('country_id');

    expect($user->refresh()->country_id)->toBe($original->id);
})->with([
    'unknown id' => [999999],
    'inactive country' => ['inactive'],
    'text' => ['Thailand'],
    'array' => [[1]],
]);

test('a user whose country was deactivated can still save other changes', function () {
    $country = Country::factory()->create();
    $user = User::factory()->create(['country_id' => $country->id]);
    $country->update(['is_active' => false]);

    // The form sends the country they already have; that must not block a name change.
    $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Still Saves', 'email' => $user->email, 'country_id' => $country->id])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->name)->toBe('Still Saves')->and($user->country_id)->toBe($country->id);
});

test('an invalid alerts preference is rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch(route('profile.update'), ['name' => $user->name, 'email' => $user->email, 'alerts_enabled' => 'maybe'])
        ->assertSessionHasErrors('alerts_enabled');
});

test('the profile form cannot change the role, the verified date or the password', function () {
    $user = User::factory()->create();
    $oldHash = $user->password;

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name, 'email' => $user->email,
        'role' => 'admin', 'email_verified_at' => null, 'password' => 'hacked-password-1', 'id' => 99999,
    ])->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->role)->toBe('user')
        ->and($user->isAdmin())->toBeFalse()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->password)->toBe($oldHash)
        ->and($user->id)->not->toBe(99999);

    $this->actingAs($user)->get('/admin')->assertForbidden();
});

test('only a signed-in user can change the profile', function () {
    $this->patch(route('profile.update'), ['name' => 'x', 'email' => 'x@example.com'])->assertRedirect(route('login'));
});

test('changing the email still clears verification, which stops alert emails', function () {
    $user = User::factory()->create(['country_id' => Country::factory()->create()->id]);

    $this->actingAs($user)->patch(route('profile.update'), ['name' => $user->name, 'email' => 'new@example.com']);

    expect($user->refresh()->email_verified_at)->toBeNull();
});
