<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\RunSourceSync;
use App\Models\SyncRun;
use App\Services\Disaster\Dashboard\SourceHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Admin Data Freshness and "Sync from sources" (HLD v1.4 §18.3, §28.8).
 *
 * The controller never contacts a provider. It decides, creates a queued `sync_runs` row, and queues
 * `RunSourceSync`, which does the fetch in a worker.
 */
class DataSourceController extends Controller
{
    /** Sources an admin may sync by hand. Weather is scheduled only. */
    private const SOURCES = ['gdacs', 'usgs'];

    private const COOLDOWN_SECONDS = 60;

    private const QUEUE_EXPIRY_MINUTES = 10;

    public function index(SourceHealthService $health): JsonResponse
    {
        return response()->json($health->adminSnapshot());
    }

    public function refresh(Request $request): JsonResponse
    {
        $validated = $request->validate(['source' => ['required', 'in:gdacs,usgs,all']]);

        $sources = $validated['source'] === 'all' ? self::SOURCES : [$validated['source']];
        $requestUuid = (string) Str::uuid();
        $results = [];

        foreach ($sources as $source) {
            $results[$source] = $this->requestSync($source, $requestUuid, $request);
        }

        return response()->json(['request_uuid' => $requestUuid, 'results' => $results], 202);
    }

    /**
     * @return array<string, mixed>
     */
    private function requestSync(string $source, string $requestUuid, Request $request): array
    {
        $now = Carbon::now();

        if ($this->lockIsHeld($source) || SyncRun::query()->where('source_key', $source)->where('status', 'running')->exists()) {
            return ['state' => 'already_running'];
        }

        $alreadyQueued = SyncRun::query()
            ->where('source_key', $source)
            ->where('status', 'queued')
            ->where('queued_at', '>=', $now->copy()->subMinutes(self::QUEUE_EXPIRY_MINUTES))
            ->exists();

        if ($alreadyQueued) {
            return ['state' => 'already_queued'];
        }

        $lastFinished = SyncRun::query()->where('source_key', $source)->whereNotNull('finished_at')->max('finished_at');

        if ($lastFinished !== null) {
            $left = self::COOLDOWN_SECONDS - Carbon::parse($lastFinished)->diffInSeconds($now, true);

            if ($left > 0) {
                return ['state' => 'cooldown', 'seconds_left' => (int) ceil($left)];
            }
        }

        $run = SyncRun::query()->create([
            'source_key' => $source,
            'status' => 'queued',
            'trigger' => 'manual',
            'requested_by' => $request->user()->id,
            'request_uuid' => $requestUuid,
            'queued_at' => $now,
        ]);

        RunSourceSync::dispatch($source, $run->id);

        return ['state' => 'queued', 'run_id' => $run->id];
    }

    /**
     * Probe the source lock without waiting, and give it back at once.
     */
    private function lockIsHeld(string $source): bool
    {
        $lock = Cache::lock("sync:{$source}", 5);

        if (! $lock->get()) {
            return true;
        }

        $lock->release();

        return false;
    }
}
