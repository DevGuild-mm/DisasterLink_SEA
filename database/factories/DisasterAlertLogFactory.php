<?php

namespace Database\Factories;

use App\Models\DisasterAlertLog;
use App\Models\DisasterEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DisasterAlertLog>
 */
class DisasterAlertLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'disaster_event_id' => DisasterEvent::factory(),
            'status' => 'pending',
            'attempts' => 0,
        ];
    }

    /**
     * A log row for an email that was accepted for delivery.
     */
    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'sent',
            'attempts' => 1,
            'last_attempt_at' => now(),
            'sent_at' => now(),
        ]);
    }
}
