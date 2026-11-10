<?php

use App\Models\Country;
use App\Models\User;

/*
 * The login, registration and other authentication pages (Phase 7.2). The project has no JavaScript test runner, so
 * the markup rules are checked in the sources, and the behaviour was checked in Chrome (see the Phase 7.2 report).
 */

function authSource(string $path): string
{
    return file_get_contents(resource_path($path));
}

test('every authentication page uses the themed layout, not the starter sidebar or the admin layout', function () {
    $app = authSource('js/app.ts');

    // Pages under auth/ get AuthLayout, and AuthLayout renders the standalone DisasterLink layout.
    expect($app)->toContain("case name.startsWith('auth/'):")->and($app)->toContain('return AuthLayout;');
    expect(authSource('js/layouts/AuthLayout.vue'))->toContain('@/layouts/auth/AuthDisasterLayout.vue');

    $layout = authSource('js/layouts/auth/AuthDisasterLayout.vue');
    expect($layout)->not->toContain('AppSidebar')->and($layout)->not->toContain('AppLayout')->and($layout)->not->toContain('Sidebar');

    // No auth page opts into a different layout.
    foreach (['Login', 'Register', 'ForgotPassword', 'ResetPassword', 'VerifyEmail', 'ConfirmPassword'] as $page) {
        expect(authSource("js/pages/auth/{$page}.vue"))->not->toContain('AppLayout');
    }
});

test('the layout has the logo, the tagline, a Back to home link and the Login | Register toggle', function () {
    $layout = authSource('js/layouts/auth/AuthDisasterLayout.vue');

    expect($layout)->toContain('ShieldAlert')
        ->and($layout)->toContain('DisasterLink')
        ->and($layout)->toContain('Stay Informed.')
        ->and($layout)->toContain('Stay Prepared.')
        ->and($layout)->toContain('Stay Safe.')
        ->and($layout)->toContain('Back to home')
        ->and($layout)->toContain('data-test="back-to-home"')
        ->and($layout)->toContain('toggle-login')
        ->and($layout)->toContain('toggle-register')
        ->and($layout)->toContain('login()')
        ->and($layout)->toContain('register()');
});

test('only Login and Register show the toggle', function () {
    expect(authSource('js/pages/auth/Login.vue'))->toContain("toggle: 'login'")
        ->and(authSource('js/pages/auth/Register.vue'))->toContain("toggle: 'register'");

    foreach (['ForgotPassword', 'ResetPassword', 'VerifyEmail', 'ConfirmPassword'] as $page) {
        expect(authSource("js/pages/auth/{$page}.vue"))->not->toContain('toggle:');
    }
});

test('the register form has a required Country select fed by the countries prop, not a hard-coded list', function () {
    $register = authSource('js/pages/auth/Register.vue');

    expect($register)->toContain('name="country_id"')
        ->and($register)->toContain('required')
        ->and($register)->toContain('v-for="country in countries"')
        ->and($register)->toContain('countries: Array<{ id: number; iso2: string; name: string }>');

    // No country name is written into the page.
    foreach (['Thailand', 'Indonesia', 'Vietnam', 'Philippines', 'Malaysia', 'Singapore', 'Myanmar', 'Cambodia', 'Laos', 'Brunei', 'Timor'] as $name) {
        expect($register)->not->toContain($name);
    }
});

test('the alerts checkbox is checked by default and always submits an explicit 1 or 0', function () {
    $register = authSource('js/pages/auth/Register.vue');

    expect($register)->toContain('const alertsEnabled = ref(true)')
        ->and($register)->toContain('v-model="alertsEnabled"')
        ->and($register)->toContain('type="hidden"')
        ->and($register)->toContain('name="alerts_enabled"')
        ->and($register)->toContain(':value="alertsEnabled ? 1 : 0"');

    // The visible checkbox has no name, so it cannot add a second, competing alerts_enabled value.
    expect($register)->not->toContain('name="alerts_checkbox"');
});

test('the alerts wording does not claim explicit consent', function () {
    $register = authSource('js/pages/auth/Register.vue');

    expect(strtolower($register))->not->toContain('consent')->and(strtolower($register))->not->toContain('opt-in')->and(strtolower($register))->not->toContain('opt in');
    expect($register)->toContain('Email me disaster alerts');
});

test('the register page receives only the active countries, in order', function () {
    Country::factory()->create(['iso2' => 'VN', 'name' => 'Vietnam', 'sort_order' => 2]);
    Country::factory()->create(['iso2' => 'TH', 'name' => 'Thailand', 'sort_order' => 1]);
    Country::factory()->create(['iso2' => 'LA', 'name' => 'Laos', 'sort_order' => 3, 'is_active' => false]);

    $this->get(route('register'))->assertInertia(fn ($page) => $page
        ->component('auth/Register')
        ->has('countries', 2)
        ->where('countries.0.iso2', 'TH')
        ->where('countries.1.iso2', 'VN')
        ->has('passwordRules'));
});

test('the other authentication pages still render', function () {
    $this->get(route('login'))->assertOk()->assertInertia(fn ($page) => $page->component('auth/Login'));
    $this->get(route('password.request'))->assertOk()->assertInertia(fn ($page) => $page->component('auth/ForgotPassword'));
    $this->get(route('password.reset', ['token' => 'abc', 'email' => 'a@example.com']))->assertOk()->assertInertia(fn ($page) => $page->component('auth/ResetPassword'));

    $this->actingAs(User::factory()->unverified()->create())->get(route('verification.notice'))
        ->assertOk()->assertInertia(fn ($page) => $page->component('auth/VerifyEmail'));
});
