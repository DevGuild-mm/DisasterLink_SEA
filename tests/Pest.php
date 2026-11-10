<?php

use App\Models\CapitalWeather;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\DisasterEvent;
use Database\Seeders\CountrySeeder;
use Database\Seeders\DataSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Fill MySQL (the only source the public pages read) with the 11 countries, a capital observation each,
 * and the given events. Each event: country ISO3 plus any disaster_events attributes.
 *
 * @param  array<int, array<string, mixed>>  $events
 */
function seedDashboardData(array $events = [], bool $withWeather = true): void
{
    (new CountrySeeder)->run();
    (new DataSourceSeeder)->run();

    $countries = Country::query()->get()->keyBy('iso3');

    if ($withWeather) {
        foreach ($countries as $country) {
            CapitalWeather::factory()->create(['country_id' => $country->id, 'temperature_c' => 28.5]);
        }
    }

    foreach ($events as $event) {
        $country = $countries->get($event['iso3'] ?? '');
        unset($event['iso3']);

        DisasterEvent::factory()->create($event + [
            'country_id' => $country?->id,
            'country_status' => $country === null ? 'unresolved' : 'resolved',
            'occurred_at' => now()->subHours(2),
        ]);
    }
}

/**
 * Mark both event sources as just synced, so the pages show them as live.
 */
function markSourcesSynced(): void
{
    DataSource::query()->whereIn('key', ['gdacs', 'usgs', 'open_meteo'])->update([
        'last_attempt_at' => now(),
        'last_success_at' => now(),
        'last_status' => 'success',
    ]);
}
