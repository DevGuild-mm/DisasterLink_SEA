<?php

use App\Models\User;

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
