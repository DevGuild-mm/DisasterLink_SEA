<?php

namespace Database\Factories;

use App\Models\Country;
use App\Models\EmergencyContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmergencyContact>
 */
class EmergencyContactFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'country_id' => Country::factory(),
            'agency_name' => fake()->company(),
            'category' => fake()->randomElement(['police', 'fire', 'ambulance', 'disaster_agency']),
            'phone' => fake()->numerify('###'),
            'is_published' => true,
            'sort_order' => 0,
        ];
    }

    /**
     * A contact that is not visible to the public.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_published' => false,
        ]);
    }
}
