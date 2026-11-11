<?php

namespace App\Console\Commands;

use App\Services\Disaster\Sync\DisasterSyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('disaster:sync {source=all : gdacs, usgs or all}')]
#[Description('Fetch disaster events from GDACS and/or USGS and store them in the database')]
class SyncDisasterEvents extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DisasterSyncService $sync): int
    {
        $requested = strtolower((string) $this->argument('source'));
        $keys = $requested === 'all' ? $sync->sourceKeys() : [$requested];

        if ($requested !== 'all' && ! in_array($requested, $sync->sourceKeys(), true)) {
            $this->components->error("Unknown source [{$requested}]. Use ".implode(', ', $sync->sourceKeys()).' or all.');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($keys as $key) {
            $run = $sync->sync($key);

            if ($run === null) {
                $this->components->warn("{$key}: another sync is already running, skipped.");

                continue;
            }

            if ($run->status === 'success') {
                $this->components->info("{$key}: fetched {$run->fetched_count}, created {$run->created_count}, updated {$run->updated_count}, skipped {$run->skipped_count} ({$run->duration_ms} ms).");

                continue;
            }

            $failed = true;
            $this->components->error("{$key}: failed. {$run->error_message}");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
