<?php

use App\Models\Country;
use App\Models\User;

function profilePageSource(): string
{
    return file_get_contents(resource_path('js/pages/settings/Profile.vue'));
}

test('settings pages use the themed DisasterLink layout, not the starter sidebar shell', function () {
    $app = file_get_contents(resource_path('js/app.ts'));
    $layout = file_get_contents(resource_path('js/layouts/settings/Layout.vue'));

    expect($app)->toContain('return SettingsLayout;')->not->toContain('[AppLayout, SettingsLayout]');

    expect($layout)
        ->toContain('<DisasterHeader')
        ->toContain('<DisasterFooter')
        ->toContain('Account Settings')
        ->toContain('Manage your account information and your disaster alert')
        ->not->toContain('Sidebar')
        ->not->toContain('AppLayout');

    foreach (['Profile', 'Security', 'Appearance'] as $page) {
        $source = file_get_contents(resource_path("js/pages/settings/{$page}.vue"));
        expect($source)->not->toContain('breadcrumbs')->not->toContain('class="sr-only">'.$page.' settings');
    }
});

test('the profile page is organised into Personal Information and Disaster Alert Preferences sections', function () {
    $source = profilePageSource();

    expect($source)
        ->toContain('Personal Information')
        ->toContain('Disaster Alert Preferences')
        ->toContain('data-test="section-personal-information"')
        ->toContain('data-test="section-alert-preferences"')
        ->toContain('countries: CountryOption[]')
        ->toContain('v-for="country in countries"')
        ->not->toContain('Thailand');
});

test('the country field is optional and can be cleared', function () {
    $source = profilePageSource();

    expect($source)
        ->toContain('data-test="country-select"')
        ->not->toMatch('/id="country_id"[^>]*\brequired\b/s')
        ->toContain('value=""')
        ->toContain('No country selected');
});

test('the alerts control submits an explicit 1 or 0 so turning alerts off is saved', function () {
    $source = profilePageSource();

    expect($source)
        ->toContain('name="alerts_enabled"')
        ->toContain(':value="alertsEnabled ? 1 : 0"')
        ->toContain('Email me disaster alerts')
        ->not->toMatch('/<Checkbox[^>]*name=/s');
});

test('the profile page shows the user country and alerts flag so the form can be pre-filled', function () {
    $country = Country::factory()->create();
    $user = User::factory()->alertsDisabled()->create(['country_id' => $country->id]);

    $this->actingAs($user)->get(route('profile.edit'))->assertInertia(fn ($page) => $page
        ->where('auth.user.country_id', $country->id)
        ->where('auth.user.alerts_enabled', false)
        ->has('countries', 1));
});

test('a user whose country is no longer active still sees it kept in the form', function () {
    $country = Country::factory()->create(['is_active' => false]);
    $user = User::factory()->create(['country_id' => $country->id]);

    $this->actingAs($user)->get(route('profile.edit'))->assertInertia(fn ($page) => $page
        ->where('auth.user.country_id', $country->id)
        ->has('countries', 0));

    expect(profilePageSource())->toContain('currentCountryUnavailable');
});

test('saving shows success feedback and validation errors are displayed', function () {
    $source = profilePageSource();

    expect($source)
        ->toContain('recentlySuccessful')
        ->toContain('data-test="profile-saved"')
        ->toContain(':message="errors.country_id"')
        ->toContain(':message="errors.alerts_enabled"')
        ->toContain(':message="errors.email"');

    $user = User::factory()->create();
    $this->actingAs($user)->from(route('profile.edit'))
        ->patch(route('profile.update'), ['name' => $user->name, 'email' => $user->email])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('inertia.flash_data.toast.message');
});
