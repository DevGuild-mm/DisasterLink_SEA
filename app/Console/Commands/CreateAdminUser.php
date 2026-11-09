<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('admin:create {--name= : Full name} {--email= : Email address} {--password= : Password (prefer the prompt; arguments appear in shell history)}')]
#[Description('Create an administrator, or promote an existing user to administrator')]
class CreateAdminUser extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->option('email') ?: text('Email address', required: true);

        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            return $this->promote($existing);
        }

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

        $user = User::create(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->forceFill(['role' => 'admin', 'email_verified_at' => now()])->save();

        $this->components->info("Administrator {$user->email} created and verified.");

        return self::SUCCESS;
    }

    /**
     * Promote an existing user and verify their email.
     */
    private function promote(User $user): int
    {
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
}
