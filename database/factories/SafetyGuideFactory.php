<?php

namespace Database\Factories;

use App\Models\SafetyGuide;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SafetyGuide>
 */
class SafetyGuideFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->sentence(3);

        return [
            'title' => $title,
            'slug' => Str::slug($title),
            'hazard_type' => fake()->randomElement(['earthquake', 'flood', 'cyclone', 'volcano', 'wildfire', 'drought']),
            'summary' => fake()->sentence(),
            'content' => "## Before\n\n- ".fake()->sentence()."\n\n## During\n\n- ".fake()->sentence()."\n\n## After\n\n- ".fake()->sentence(),
            'country_id' => null,
            'is_published' => true,
            'sort_order' => 0,
        ];
    }

    /**
     * A guide that is not visible to the public.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_published' => false,
        ]);
    }
}
