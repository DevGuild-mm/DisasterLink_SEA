<?php

use App\Models\Country;
use App\Models\User;

test('registration ignores a role supplied in the payload', function () {
    $this->post(route('register.store'), [
        'name' => 'Sneaky User',
        'email' => 'sneaky@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'country_id' => Country::factory()->create()->id,
        'role' => 'admin',
    ]);

    $user = User::where('email', 'sneaky@example.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->role)->toBe('user')
        ->and($user->isAdmin())->toBeFalse();
});

test('role cannot be mass assigned', function () {
    $user = User::create([
        'name' => 'Mass Assign',
        'email' => 'mass@example.com',
        'password' => 'password',
        'role' => 'admin',
    ]);

    expect($user->fresh()->role)->toBe('user');
});

test('country and alerts can be mass assigned, but the role still cannot', function () {
    $country = Country::factory()->create();

    $user = User::create([
        'name' => 'Mass Assign Two',
        'email' => 'mass2@example.com',
        'password' => 'password',
        'country_id' => $country->id,
        'alerts_enabled' => false,
        'role' => 'admin',
        'email_verified_at' => now(),
    ])->fresh();

    expect($user->country_id)->toBe($country->id)
        ->and($user->alerts_enabled)->toBeFalse()
        ->and($user->role)->toBe('user')
        ->and($user->email_verified_at)->toBeNull();
});

test('unverified admins are redirected away from admin routes', function () {
    config(['modules.reports' => true]);
    $admin = User::factory()->unverified()->create(['role' => 'admin']);

    $this->actingAs($admin)->get('/admin/reports')->assertRedirect(route('verification.notice'));
});

test('verified admins can open admin routes', function () {
    config(['modules.reports' => true]);
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->get('/admin/reports')->assertOk();
});
