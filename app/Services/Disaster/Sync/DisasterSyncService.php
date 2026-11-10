<?php

namespace App\Services\Disaster\Sync;

use App\Models\DataSource;
use App\Models\DisasterEvent;
use App\Models\SyncRun;
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
 * A failed run never changes stored events and never updates `last_success_at`.
 */
class DisasterSyncService
{
    private const CHUNK_SIZE = 200;

    /**
     * @var array<string, SourceClient>
     */
    private array $clients;

    public function __construct(GdacsClient $gdacs, UsgsClient $usgs, private CountryResolver $countryResolver)
    {
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
     * @return SyncRun|null null when another run of the same source holds the lock
     *
     * @throws InvalidArgumentException for an unknown source
     */
    public function sync(string $key): ?SyncRun
    {
        $client = $this->clients[$key] ?? throw new InvalidArgumentException("Unknown disaster source [{$key}].");

        $lock = Cache::lock("sync:{$key}", 300);

        if (! $lock->get()) {
            return null;
        }

        try {
            return $this->run($client);
        } finally {
            $lock->release();
        }
    }

    private function run(SourceClient $client): SyncRun
    {
        $key = $client->key();
        $startedAt = Carbon::now();

        $source = DataSource::query()->firstOrCreate(['key' => $key], [
            'name' => (string) config("disaster_sources.sources.{$key}.name", $key),
            'pipeline' => 'events',
            'interval_minutes' => (int) config("disaster_sources.sources.{$key}.interval_minutes", 15),
        ]);

        $source->update(['last_attempt_at' => $startedAt, 'last_status' => 'running']);

        $run = SyncRun::query()->create([
            'source_key' => $key,
            'status' => 'running',
            'started_at' => $startedAt,
        ]);

        $timer = hrtime(true);

        try {
            $result = $client->fetch();
            $counts = $this->persist($key, $result);
        } catch (Throwable $e) {
            return $this->fail($source, $run, $e, $this->durationMs($timer));
        }

        $run->update([
            'status' => 'success',
            'http_status' => $result->httpStatus,
            'fetched_count' => $result->fetchedCount,
            'processed_count' => $counts['created'] + $counts['updated'],
            'created_count' => $counts['created'],
            'updated_count' => $counts['updated'],
            'skipped_count' => $result->skippedCount,
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

        Cache::forget('dashboard:current:v1');

        return $run;
    }

    /**
     * Upsert by (source, external_id). Existing rows keep first_seen_at, country and alert fields.
     *
     * @return array{created: int, updated: int}
     */
    private function persist(string $source, FetchResult $result): array
    {
        $now = Carbon::now();

        $rows = [];
        foreach ($result->events as $event) {
            $rows[$event->externalId] = $event->toRow($now);
        }

        if ($rows === []) {
            return ['created' => 0, 'updated' => 0];
        }

        $this->countryResolver->flush();

        return DB::transaction(function () use ($source, $rows): array {
            $before = [];

            foreach (array_chunk(array_keys($rows), self::CHUNK_SIZE) as $ids) {
                DisasterEvent::query()
                    ->where('source', $source)
                    ->whereIn('external_id', $ids)
                    ->get(['external_id', 'latitude', 'longitude', 'country_status', 'source_meta'])
                    ->each(function (DisasterEvent $event) use (&$before): void {
                        $before[$event->external_id] = $event;
                    });
            }

            foreach (array_chunk(array_values($rows), self::CHUNK_SIZE) as $chunk) {
                DisasterEvent::query()->upsert($chunk, ['source', 'external_id'], NormalizedEvent::updatableColumns());
            }

            $this->resolveCountries($source, $rows, $before);

            $existing = count($before);

            return ['created' => count($rows) - $existing, 'updated' => $existing];
        });
    }

    /**
     * Resolve the country (HLD §12) for new events, events whose coordinates or source country changed,
     * and events still unresolved (so newly loaded boundaries are picked up). Other events keep their country.
     *
     * @param  array<string, array<string, mixed>>  $rows  upsert rows by external id
     * @param  array<string, DisasterEvent>  $before  stored events before the upsert
     */
    private function resolveCountries(string $source, array $rows, array $before): void
    {
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
        }
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
