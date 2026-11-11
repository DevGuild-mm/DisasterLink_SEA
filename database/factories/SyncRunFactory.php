<?php

namespace Database\Factories;

use App\Models\DataSource;
use App\Models\SyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncRun>
 */
class SyncRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_key' => DataSource::factory(),
            'status' => 'success',
            'http_status' => 200,
            'fetched_count' => 10,
            'processed_count' => 10,
            'created_count' => 2,
            'updated_count' => 8,
            'skipped_count' => 0,
            'duration_ms' => 500,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ];
    }
}
