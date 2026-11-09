<?php

namespace Database\Factories;

use App\Models\CapitalWeather;
use App\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CapitalWeather>
 */
class CapitalWeatherFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'country_id' => Country::factory(),
            'temperature_c' => fake()->randomFloat(1, 22, 35),
            'weather_code' => 2,
            'condition' => 'Partly cloudy',
            'icon' => 'cloud',
            'precipitation_mm' => 0,
            'wind_speed_kmh' => fake()->randomFloat(1, 0, 30),
            'humidity_pct' => fake()->numberBetween(40, 95),
            'forecast_min_c' => 24,
            'forecast_max_c' => 33,
            'observed_at' => now()->subMinutes(10),
            'fetched_at' => now(),
        ];
    }
}
