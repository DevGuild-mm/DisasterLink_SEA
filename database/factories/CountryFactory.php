<?php

namespace Database\Factories;

use App\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Country>
 */
class CountryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'iso2' => strtoupper(fake()->unique()->lexify('??')),
            'iso3' => strtoupper(fake()->unique()->lexify('???')),
            'name' => fake()->unique()->country(),
            'timezone' => 'Asia/Bangkok',
            'capital_name' => fake()->city(),
            'capital_lat' => fake()->latitude(-10, 20),
            'capital_lng' => fake()->longitude(95, 130),
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
