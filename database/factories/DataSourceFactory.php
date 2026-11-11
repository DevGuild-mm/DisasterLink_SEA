<?php

namespace Database\Factories;

use App\Models\DataSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DataSource>
 */
class DataSourceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'name' => fake()->words(2, true),
            'pipeline' => 'events',
            'interval_minutes' => 15,
            'consecutive_failures' => 0,
        ];
    }
}
