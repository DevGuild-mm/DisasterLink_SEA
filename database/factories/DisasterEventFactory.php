<?php

namespace Database\Factories;

use App\Models\DisasterEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DisasterEvent>
 */
class DisasterEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $occurredAt = now()->subHour();

        return [
            'source' => 'gdacs',
            'external_id' => 'FL:'.fake()->unique()->numberBetween(1000, 999999),
            'event_type' => 'flood',
            'title' => fake()->sentence(4),
            'alert_level' => 'Green',
            'latitude' => fake()->latitude(-10, 20),
            'longitude' => fake()->longitude(95, 130),
            'occurred_at' => $occurredAt,
            'country_status' => 'unresolved',
            'alert_status' => 'not_eligible',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'last_synced_at' => now(),
        ];
    }

    /**
     * A USGS earthquake of the given magnitude.
     */
    public function usgsEarthquake(float $magnitude = 5.5): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => 'usgs',
            'external_id' => 'us'.fake()->unique()->numerify('########'),
            'event_type' => 'earthquake',
            'alert_level' => null,
            'magnitude' => $magnitude,
        ]);
    }

    /**
     * A GDACS event at the given alert level.
     */
    public function gdacsAlert(string $level): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => 'gdacs',
            'alert_level' => $level,
        ]);
    }
}
