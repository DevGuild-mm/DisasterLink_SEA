<?php

namespace App\Jobs;

use App\Models\SyncRun;
use App\Services\Disaster\Sync\DisasterSyncService;
use App\Services\Disaster\Weather\WeatherRefreshService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * The admin "Sync from sources" job for one source and one queued `sync_runs` row (HLD v1.4 §28.8).
 *
 * It really fetches from the provider, through the same service and `sync:{source}` lock as the scheduler.
 * `tries = 1`: a crashed run is never repeated. When the lock is held by another run the job exits and deletes
 * its own unclaimed row; it never releases itself back to the queue.
 */
class RunSourceSync implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    /** Must exceed `retry_after` (config/queue.php, 360 s), so the unique lock outlives a redelivery. */
    public int $uniqueFor = 360;

    public function __construct(public string $source, public int $runId)
    {
        $this->timeout = (int) config('disaster_sources.sync.job_timeout_seconds', 240);
    }

    public function uniqueId(): string
    {
        return $this->source;
    }

    public function handle(DisasterSyncService $sync, ?WeatherRefreshService $weather = null): void
    {
        $run = $this->source === 'open_meteo'
            ? ($weather ?? app(WeatherRefreshService::class))->refresh($this->runId)
            : $sync->sync($this->source, $this->runId);

        if ($run !== null) {
            return;
        }

        // The lock was taken by another run after this one was queued. Nothing was fetched and the row was never
        // claimed: remove it only if it is still queued, and leave any row the sweep already marked failed.
        $removed = SyncRun::query()->whereKey($this->runId)->where('status', 'queued')->delete();

        Log::info("Manual {$this->source} sync joined an already-running sync (row {$this->runId}, removed {$removed}).");
    }
}
