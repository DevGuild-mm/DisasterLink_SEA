<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('the old dashboard link sends a verified user to the Welcome page', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('dashboard'))->assertRedirect('/');
});

test('the old dashboard link sends an administrator to /admin', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->get(route('dashboard'))->assertRedirect('/admin');
});

test('the old dashboard link still needs a verified email', function () {
    $this->actingAs(User::factory()->unverified()->create());

    $this->get(route('dashboard'))->assertRedirect(route('verification.notice'));
});
