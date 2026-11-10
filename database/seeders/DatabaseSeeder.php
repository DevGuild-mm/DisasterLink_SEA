<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database (local development data).
     */
    public function run(): void
    {
        $this->call([
            CountrySeeder::class,
            CountryBoundarySeeder::class,
            DataSourceSeeder::class,
        ]);

        $thailand = Country::query()->where('iso2', 'TH')->first();

        User::query()->firstOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => 'password', 'email_verified_at' => now()],
        )->forceFill(['country_id' => $thailand?->id])->save();

        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Local Admin', 'password' => 'password', 'email_verified_at' => now()],
        );
        $admin->forceFill(['role' => 'admin', 'country_id' => $thailand?->id])->save();
    }
}
