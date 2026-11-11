<?php

namespace App\Services\Disaster\Sync;

use App\Jobs\ProcessEventAlerts;
use App\Models\DataSource;
use App\Models\DisasterEvent;
use App\Models\SyncRun;
use App\Services\Disaster\Alerts\AlertEligibility;
use App\Services\Disaster\Dashboard\DashboardCache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Runs one source sync (HLD §11.1): fetch, validate, upsert, record the run and source health.
 *
 * A failed run never changes stored events, never updates `last_success_at` and never touches the cache.
 *
 * Counts on a successful run: `created_count` is new events, `updated_count` is existing events whose
 * meaningful fields (see EventChangeDetector) or resolved country changed, `processed_count` is their sum.
 * Events seen again without a meaningful change are refreshed (`last_seen_at`) but not counted.
 * Cached data is invalidated only after the transaction commits, and only when those counts are not zero.
 */
class DisasterSyncService
{
    private const CHUNK_SIZE = 200;

    /** A queued manual run that no worker claimed in this time is expired (HLD §28.8). */
    private const QUEUE_EXPIRY_MINUTES = 10;

    /**
     * @var array<string, SourceClient>
     */
    private array $clients;

    public function __construct(
        GdacsClient $gdacs,
        UsgsClient $usgs,
        private CountryResolver $countryResolver,
        private EarthquakeLinker $linker,
        private AlertEligibility $eligibility,
        private DashboardCache $dashboardCache,
    ) {
        $this->clients = [
            $gdacs->key() => $gdacs,
            $usgs->key() => $usgs,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function sourceKeys(): array
    {
        return array_keys($this->clients);
    }

    /**
     * Run one source under the `sync:{source}` lock.
     *
     * With `$queuedRunId` (the admin "Sync from sources" job, HLD §28.8) the order is fixed: take the lock without
     * waiting, sweep abandoned rows, then claim the queued row with one atomic update. A row that is expired or
     * already claimed is not run and is returned unchanged.
     *
     * @return SyncRun|null null when another run of the same source holds the lock
     *
     * @throws InvalidArgumentException for an unknown source
     */
    public function sync(string $key, ?int $queuedRunId = null): ?SyncRun
    {
        $client = $this->clients[$key] ?? throw new InvalidArgumentException("Unknown disaster source [{$key}].");

        $lock = Cache::lock("sync:{$key}", (int) config('disaster_sources.sync.lock_seconds', 300));

        if (! $lock->get()) {
            return null;
        }

        try {
            $this->sweepAbandoned($key);

            if ($queuedRunId === null) {
                return $this->run($client);
            }

            $claimed = SyncRun::query()
                ->where('id', $queuedRunId)
                ->where('status', 'queued')
                ->where('queued_at', '>=', Carbon::now()->subMinutes(self::QUEUE_EXPIRY_MINUTES))
                ->update(['status' => 'running', 'started_at' => Carbon::now()]);

            if ($claimed === 0) {
                Log::info("Sync run {$queuedRunId} for {$key} was not claimed (expired or already taken).");

                return SyncRun::query()->find($queuedRunId);
            }

            return $this->run($client, SyncRun::query()->findOrFail($queuedRunId));
        } finally {
            $lock->release();
        }
    }

    /**
     * HLD §28.10. Runs only while this process holds the source lock, so no live run exists for the source.
     * Marks leftover `running` rows failed and queued rows older than the expiry failed.
     */
    private function sweepAbandoned(string $key): void
    {
        $now = Carbon::now();

        $abandoned = SyncRun::query()->where('source_key', $key)->where('status', 'running')->update([
            'status' => 'failed',
            'error_message' => 'Abandoned: the process stopped before finishing.',
            'finished_at' => $now,
        ]);

        SyncRun::query()
            ->where('source_key', $key)
            ->where('status', 'queued')
            ->where('queued_at', '<', $now->copy()->subMinutes(self::QUEUE_EXPIRY_MINUTES))
            ->update(['status' => 'failed', 'error_message' => 'Not picked up by a worker.', 'finished_at' => $now]);

        if ($abandoned > 0) {
            DataSource::query()->where('key', $key)->where('last_status', 'running')->update(['last_status' => 'failed']);
        }
    }

    private function run(SourceClient $client, ?SyncRun $claimed = null): SyncRun
    {
        $key = $client->key();
        $startedAt = $claimed?->started_at ?? Carbon::now();

        $source = DataSource::query()->firstOrCreate(['key' => $key], [
            'name' => (string) config("disaster_sources.sources.{$key}.name", $key),
            'pipeline' => 'events',
            'interval_minutes' => (int) config("disaster_sources.sources.{$key}.interval_minutes", 15),
        ]);

        $source->update(['last_attempt_at' => $startedAt, 'last_status' => 'running']);

        $run = $claimed ?? SyncRun::query()->create([
            'source_key' => $key,
            'status' => 'running',
            'started_at' => $startedAt,
        ]);

        $timer = hrtime(true);

        try {
            $client->useDeadline(new SyncDeadline((int) config('disaster_sources.sync.deadline_seconds', 150)));
            $result = $client->fetch();
            $counts = $this->persist($key, $result);
        } catch (Throwable $e) {
            return $this->fail($source, $run, $e, $this->durationMs($timer));
        } finally {
            $client->useDeadline(null);
        }

        $run->update([
            'status' => 'success',
            'http_status' => $result->httpStatus,
            'fetched_count' => $result->fetchedCount,
            'processed_count' => $counts['created'] + $counts['updated'],
            'created_count' => $counts['created'],
            'updated_count' => $counts['updated'],
            'skipped_count' => $result->skippedCount,
            'warning_message' => $result->warning,
            'duration_ms' => $this->durationMs($timer),
            'finished_at' => Carbon::now(),
        ]);

        $source->update([
            'last_success_at' => Carbon::now(),
            'last_status' => 'success',
            'last_error' => null,
            'consecutive_failures' => 0,
            'last_fetched_count' => $result->fetchedCount,
            'last_processed_count' => $counts['created'] + $counts['updated'],
            'last_empty_success_at' => $result->fetchedCount === 0 ? Carbon::now() : $source->last_empty_success_at,
        ]);

        $this->invalidateCaches($counts);
        $this->dispatchAlerts($counts['alerts']);

        return $run;
    }

    /**
     * Queue the alert jobs for events that just became eligible. Runs after the commit. A queue problem must not
     * fail the sync; `alerts:dispatch` picks up any event left pending.
     *
     * @param  array<int, int>  $eventIds
     */
    private function dispatchAlerts(array $eventIds): void
    {
        foreach ($eventIds as $eventId) {
            try {
                ProcessEventAlerts::dispatch($eventId);
            } catch (Throwable $e) {
                Log::warning("Could not queue alerts for event {$eventId}: {$e->getMessage()}");
            }
        }
    }

    /**
     * Called only after a successful commit. Nothing changes for the UI when no event was created or
     * meaningfully updated, so the cached payload (and its country entries) stay.
     *
     * @param  array{created: int, updated: int, countries: array<int, int>, links: int, alerts: array<int, int>}  $counts
     */
    private function invalidateCaches(array $counts): void
    {
        if ($counts['created'] + $counts['updated'] + $counts['links'] === 0) {
            return;
        }

        $this->dashboardCache->invalidateAfterSync($counts['countries']);
    }

    /**
     * Upsert by (source, external_id). Existing rows keep first_seen_at, country and alert fields.
     *
     * @return array{created: int, updated: int, countries: array<int, int>, links: int, alerts: array<int, int>} `countries` are the ids of
     *                                                                                                            every country whose events changed (old and new)
     */
    private function persist(string $source, FetchResult $result): array
    {
        $now = Carbon::now();

        $rows = [];
        foreach ($result->events as $event) {
            $rows[$event->externalId] = $event->toRow($now);
        }

        if ($rows === []) {
            return ['created' => 0, 'updated' => 0, 'countries' => [], 'links' => 0, 'alerts' => []];
        }

        $this->countryResolver->flush();

        return DB::transaction(function () use ($source, $rows, $now): array {
            $before = [];

            foreach (array_chunk(array_keys($rows), self::CHUNK_SIZE) as $ids) {
                DisasterEvent::query()
                    ->where('source', $source)
                    ->whereIn('external_id', $ids)
                    ->get(EventChangeDetector::columns())
                    ->each(function (DisasterEvent $event) use (&$before): void {
                        $before[$event->external_id] = $event;
                    });
            }

            foreach (array_chunk(array_values($rows), self::CHUNK_SIZE) as $chunk) {
                DisasterEvent::query()->upsert($chunk, ['source', 'external_id'], NormalizedEvent::updatableColumns());
            }

            $countries = $this->resolveCountries($source, $rows, $before);

            $created = 0;
            $updated = 0;
            $affected = [];

            foreach ($rows as $externalId => $row) {
                $old = $before[$externalId] ?? null;
                $countryChanged = isset($countries[$externalId]) && $countries[$externalId]['changed'];

                if ($old === null) {
                    $created++;
                } elseif ($countryChanged || EventChangeDetector::differs($row, $old)) {
                    $updated++;
                } else {
                    continue;
                }

                foreach ([$old?->country_id, $countries[$externalId]['new'] ?? $old?->country_id] as $countryId) {
                    if ($countryId !== null) {
                        $affected[(int) $countryId] = (int) $countryId;
                    }
                }
            }

            $linked = $this->linker->link(
                DisasterEvent::query()->where('source', $source)->whereIn('external_id', array_keys($rows))->pluck('id')->all(),
            );

            return [
                'created' => $created,
                'updated' => $updated,
                'countries' => array_values($affected),
                'links' => $linked['changes'],
                'alerts' => $this->eligibility->evaluate($linked['canonical'], $now),
            ];
        });
    }

    /**
     * Resolve the country (HLD §12) for new events, events whose coordinates or source country changed,
     * and events still unresolved (so newly loaded boundaries are picked up). Other events keep their country.
     *
     * @param  array<string, array<string, mixed>>  $rows  upsert rows by external id
     * @param  array<string, DisasterEvent>  $before  stored events before the upsert
     * @return array<string, array{new: int|null, changed: bool}> the events that were resolved again
     */
    private function resolveCountries(string $source, array $rows, array $before): array
    {
        $resolved = [];

        foreach ($rows as $externalId => $row) {
            $old = $before[$externalId] ?? null;
            $meta = (array) json_decode((string) $row['source_meta'], true);
            $iso3 = isset($meta['iso3']) ? (string) $meta['iso3'] : null;

            if ($old !== null
                && $old->country_status !== CountryResolution::UNRESOLVED
                && $this->sameCoordinate($old->latitude, $row['latitude'])
                && $this->sameCoordinate($old->longitude, $row['longitude'])
                && ($old->source_meta['iso3'] ?? null) === $iso3) {
                continue;
            }

            $resolution = $this->countryResolver->resolve(
                $iso3,
                $row['latitude'] !== null ? (float) $row['latitude'] : null,
                $row['longitude'] !== null ? (float) $row['longitude'] : null,
            );

            DisasterEvent::query()
                ->where('source', $source)
                ->where('external_id', $externalId)
                ->update($resolution->toColumns());

            $resolved[$externalId] = [
                'new' => $resolution->countryId,
                'changed' => $old !== null && ($old->country_id !== $resolution->countryId || $old->country_status !== $resolution->status),
            ];
        }

        return $resolved;
    }

    private function sameCoordinate(mixed $stored, mixed $incoming): bool
    {
        if ($stored === null || $incoming === null) {
            return $stored === $incoming;
        }

        return abs((float) $stored - (float) $incoming) < 0.0000005;
    }

    private function fail(DataSource $source, SyncRun $run, Throwable $e, int $durationMs): SyncRun
    {
        $message = Str::limit($e->getMessage(), 500, '');

        Log::warning("Disaster sync failed for {$source->key}: {$message}");

        $run->update([
            'status' => 'failed',
            'http_status' => $e instanceof SourceFetchException ? $e->httpStatus : null,
            'error_message' => $message,
            'duration_ms' => $durationMs,
            'finished_at' => Carbon::now(),
        ]);

        $source->update([
            'last_status' => 'failed',
            'last_error' => $message,
            'consecutive_failures' => $source->consecutive_failures + 1,
        ]);

        return $run;
    }

    private function durationMs(int $timer): int
    {
        return (int) round((hrtime(true) - $timer) / 1_000_000);
    }
}
