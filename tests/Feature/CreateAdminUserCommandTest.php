<?php

use App\Models\Country;
use App\Models\User;
use Database\Seeders\CountrySeeder;

test('it creates a verified administrator', function () {
    $this->artisan('admin:create', [
        '--name' => 'Ada Admin',
        '--email' => 'ada@example.com',
        '--password' => 'a-Strong-pass-123',
    ])->assertSuccessful();

    $user = User::where('email', 'ada@example.com')->first();

    expect($user->isAdmin())->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->password)->not->toBe('a-Strong-pass-123');
});

test('it promotes an existing user and verifies them', function () {
    $user = User::factory()->unverified()->create(['email' => 'bob@example.com']);

    $this->artisan('admin:create', ['--email' => 'bob@example.com'])->assertSuccessful();

    $user->refresh();

    expect($user->isAdmin())->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeTrue();
});

test('it leaves an existing administrator unchanged', function () {
    $admin = User::factory()->admin()->create(['email' => 'root@example.com']);

    $this->artisan('admin:create', ['--email' => 'root@example.com'])->assertSuccessful();

    expect(User::where('role', 'admin')->count())->toBe(1)
        ->and($admin->fresh()->isAdmin())->toBeTrue();
});

test('it rejects a weak password and creates nothing', function () {
    $this->artisan('admin:create', [
        '--name' => 'Weak',
        '--email' => 'weak@example.com',
        '--password' => '123',
    ])->assertFailed();

    expect(User::where('email', 'weak@example.com')->exists())->toBeFalse();
});

test('it rejects an invalid email and creates nothing', function () {
    $this->artisan('admin:create', [
        '--name' => 'Bad',
        '--email' => 'not-an-email',
        '--password' => 'a-Strong-pass-123',
    ])->assertFailed();

    expect(User::count())->toBe(0);
});

// ---- optional country (Phase 7.1) ----

test('it creates an administrator without a country when none is given', function () {
    (new CountrySeeder)->run();

    $this->artisan('admin:create', ['--name' => 'No Country', '--email' => 'nc@example.com', '--password' => 'a-Strong-pass-123'])
        ->assertSuccessful();

    $user = User::where('email', 'nc@example.com')->first();
    expect($user->country_id)->toBeNull()->and($user->isAdmin())->toBeTrue();
});

test('it sets the country from an ISO2 code, in any case', function (string $code) {
    (new CountrySeeder)->run();

    $this->artisan('admin:create', ['--name' => 'Ada', '--email' => 'ada@example.com', '--password' => 'a-Strong-pass-123', '--country' => $code])
        ->assertSuccessful();

    $user = User::where('email', 'ada@example.com')->first();
    expect($user->country->iso2)->toBe('TH')->and($user->isAdmin())->toBeTrue()->and($user->hasVerifiedEmail())->toBeTrue();
})->with(['TH', 'th', ' Th ']);

test('it rejects an unknown country code and creates nothing', function (string $code) {
    (new CountrySeeder)->run();

    $this->artisan('admin:create', ['--name' => 'Bad', '--email' => 'bad@example.com', '--password' => 'a-Strong-pass-123', '--country' => $code])
        ->assertFailed();

    expect(User::where('email', 'bad@example.com')->exists())->toBeFalse();
})->with(['ZZ', 'THA', 'Thailand', 'T']);

test('it rejects an inactive country and creates nothing', function () {
    (new CountrySeeder)->run();
    Country::where('iso2', 'TH')->update(['is_active' => false]);

    $this->artisan('admin:create', ['--name' => 'Bad', '--email' => 'bad@example.com', '--password' => 'a-Strong-pass-123', '--country' => 'TH'])
        ->assertFailed();

    expect(User::where('email', 'bad@example.com')->exists())->toBeFalse();
});

test('a bad country stops a promotion too and changes nothing', function () {
    (new CountrySeeder)->run();
    $user = User::factory()->unverified()->create(['email' => 'bob@example.com']);

    $this->artisan('admin:create', ['--email' => 'bob@example.com', '--country' => 'ZZ'])->assertFailed();

    expect($user->fresh()->isAdmin())->toBeFalse()->and($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('promoting a user with a country sets it, and the role rule is unchanged', function () {
    (new CountrySeeder)->run();
    $user = User::factory()->unverified()->create(['email' => 'bob@example.com', 'country_id' => null]);

    $this->artisan('admin:create', ['--email' => 'bob@example.com', '--country' => 'VN'])->assertSuccessful();

    $user->refresh();
    expect($user->country->iso2)->toBe('VN')->and($user->isAdmin())->toBeTrue()->and($user->hasVerifiedEmail())->toBeTrue();
});

test('promoting a user without --country keeps the country they have', function () {
    (new CountrySeeder)->run();
    $country = Country::where('iso2', 'ID')->first();
    $user = User::factory()->create(['email' => 'bob@example.com', 'country_id' => $country->id]);

    $this->artisan('admin:create', ['--email' => 'bob@example.com'])->assertSuccessful();

    expect($user->fresh()->country_id)->toBe($country->id)->and($user->fresh()->isAdmin())->toBeTrue();
});

test('an existing administrator without a country stays valid and unchanged', function () {
    (new CountrySeeder)->run();
    $admin = User::factory()->admin()->create(['email' => 'root@example.com', 'country_id' => null]);

    $this->artisan('admin:create', ['--email' => 'root@example.com'])->assertSuccessful();

    expect($admin->fresh()->isAdmin())->toBeTrue()->and($admin->fresh()->country_id)->toBeNull();
});

test('an existing administrator can be given a country with --country', function () {
    (new CountrySeeder)->run();
    $admin = User::factory()->admin()->create(['email' => 'root@example.com', 'country_id' => null]);

    $this->artisan('admin:create', ['--email' => 'root@example.com', '--country' => 'MY'])->assertSuccessful();

    expect($admin->fresh()->country->iso2)->toBe('MY')->and($admin->fresh()->isAdmin())->toBeTrue();
});

test('an interactive run asks for the country', function () {
    (new CountrySeeder)->run();

    // The prompt's fallback lists each option's key and its label.
    $choices = ['' => 'No country (set it later in Settings)'] + Country::query()->active()->orderBy('sort_order')->pluck('name', 'iso2')->all();
    $options = array_merge(array_keys($choices), array_values($choices));

    $this->artisan('admin:create', ['--email' => 'asked@example.com'])
        ->expectsQuestion('Name', 'Asked Admin')
        ->expectsQuestion('Password', 'a-Strong-pass-123')
        ->expectsChoice('Country (optional)', 'TH', $options)
        ->assertSuccessful();

    expect(User::where('email', 'asked@example.com')->first()->country->iso2)->toBe('TH');
});

test('the country option does not let anything else through: the role is only set by the command', function () {
    (new CountrySeeder)->run();

    $this->artisan('admin:create', ['--name' => 'Ada', '--email' => 'ada@example.com', '--password' => 'a-Strong-pass-123', '--country' => 'TH'])
        ->assertSuccessful();

    expect(User::where('role', 'admin')->count())->toBe(1);
});
