<?php

namespace App\Console\Commands;

use App\Models\Country;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

#[Signature('admin:create {--name= : Full name} {--email= : Email address} {--password= : Password (prefer the prompt; arguments appear in shell history)} {--country= : Country, as an ISO2 code of an active country (for example TH). Optional}')]
#[Description('Create an administrator, or promote an existing user to administrator')]
class CreateAdminUser extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $country = $this->resolveOptionCountry();

        if ($country === false) {
            return self::FAILURE;
        }

        $prompted = ! $this->option('email');
        $email = $this->option('email') ?: text('Email address', required: true);

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            return $this->promote($existing, $country);
        }

        $prompted = $prompted || ! $this->option('name') || ! $this->option('password');
        $name = $this->option('name') ?: text('Name', required: true);
        $password = $this->option('password') ?: password('Password', required: true);

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255'],
                'password' => ['required', 'string', Password::default()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        // A fully scripted run (name, email and password given as options) never prompts. An interactive run
        // offers the country, because an administrator without one receives no country alerts.
        $country ??= $prompted ? $this->askForCountry() : null;

        $user = User::create(['name' => $name, 'email' => $email, 'password' => $password, 'country_id' => $country?->id]);
        $user->forceFill(['role' => 'admin', 'email_verified_at' => now()])->save();

        $this->components->info("Administrator {$user->email} created and verified".($country ? " for {$country->name}." : '.'));

        if ($country === null) {
            $this->components->warn('No country set: this administrator receives no country alerts until one is chosen in Settings.');
        }

        return self::SUCCESS;
    }

    /**
     * Promote an existing user and verify their email. An explicit country is applied; a country the user
     * already has is otherwise left alone.
     */
    private function promote(User $user, ?Country $country): int
    {
        if ($country !== null) {
            $user->forceFill(['country_id' => $country->id])->save();
        }

        if ($user->isAdmin()) {
            $this->components->info("{$user->email} is already an administrator.");

            return self::SUCCESS;
        }

        $user->forceFill([
            'role' => 'admin',
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        $this->components->info("{$user->email} is now an administrator.");

        return self::SUCCESS;
    }

    /**
     * The country given with `--country`: null when the option is absent or empty, false (after an error) when
     * it is not an active country.
     */
    private function resolveOptionCountry(): Country|false|null
    {
        $code = trim((string) $this->option('country'));

        if ($code === '') {
            return null;
        }

        $country = Country::query()->active()->where('iso2', strtoupper($code))->first();

        if ($country === null) {
            $this->components->error("[{$code}] is not an active country. Use an ISO2 code such as TH, ID or VN.");

            return false;
        }

        return $country;
    }

    private function askForCountry(): ?Country
    {
        $options = ['' => 'No country (set it later in Settings)']
            + Country::query()->active()->orderBy('sort_order')->pluck('name', 'iso2')->all();

        $code = (string) select('Country (optional)', $options, default: '');

        return $code === '' ? null : Country::query()->active()->where('iso2', $code)->first();
    }
}
