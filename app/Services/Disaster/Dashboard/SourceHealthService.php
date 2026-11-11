<?php

namespace App\Services\Disaster\Dashboard;

use App\Models\CapitalWeather;
use App\Models\DataSource;
use App\Models\DisasterEvent;
use App\Models\SyncRun;
use Illuminate\Support\Carbon;

/**
 * Status of the three sources, always read live from `data_sources` and never cached (HLD v1.4 §28.5),
 * so a no-change sync keeps the displayed "last successful sync" accurate without clearing the dashboard cache.
 */
class SourceHealthService
{
    private const DOCS = [
        'gdacs' => ['GDACS', 'Global Disaster Alert and Coordination System (UN / EC JRC)', 'Every 15 minutes (scheduled sync)', 'https://www.gdacs.org/gdacsapi/swagger/index.html'],
        'usgs' => ['USGS', 'United States Geological Survey (Earthquake Hazards Program)', 'Every 5 minutes (scheduled sync)', 'https://earthquake.usgs.gov/earthquakes/feed/v1.0/summary/all_day.geojson'],
        'open_meteo' => ['Open-Meteo', 'Open-Meteo Weather Forecast API', 'Every 30 minutes (scheduled sync)', 'https://open-meteo.com/en/docs'],
    ];

    /** Runs listed per source in the admin history. */
    private const HISTORY_PER_SOURCE = 10;

    /** A queued run older than this many seconds suggests that no queue worker is running (HLD §28.8). */
    private const WORKER_WARNING_SECONDS = 120;

    /** A running row older than the lock TTL plus a margin is shown as abandoned without writing (HLD §28.10). */
    private const ABANDONED_SECONDS = 360;

    /**
     * Delayed and Stale thresholds in minutes (HLD §18.2). The weather limits come from config, the one policy the
     * admin status, the public warning and the catch-up refresh all read.
     *
     * @return array<string, array{int, int}>
     */
    private function thresholds(): array
    {
        return [
            'usgs' => [10, 30],
            'gdacs' => [30, 60],
            'open_meteo' => [
                (int) config('disaster_sources.sources.open_meteo.delayed_after_minutes', 60),
                (int) config('disaster_sources.sources.open_meteo.stale_after_minutes', 180),
            ],
        ];
    }

    /**
     * Admin Data Freshness: one card per source with the HLD §18.2 status, the latest run, and the last 10 runs of each source.
     *
     * @return array{sources: array<int, array<string, mixed>>, runs: array<int, array<string, mixed>>}
     */
    public function adminSnapshot(): array
    {
        $now = Carbon::now();
        $rows = DataSource::query()->get()->keyBy('key');
        $cards = [];

        foreach ($this->thresholds() as $key => [$delayed, $stale]) {
            $row = $rows->get($key);
            $latest = SyncRun::query()->where('source_key', $key)->latest('id')->first();
            $success = $row?->last_success_at;
            $age = $success === null ? null : $success->diffInMinutes($now, true);

            $status = match (true) {
                $row === null || $row->last_attempt_at === null => 'Never Synced',
                $row->last_status === 'failed' => $row->consecutive_failures >= 3 ? 'Failed (repeated)' : 'Failed',
                $success === null || $age > $stale => 'Stale',
                $age > $delayed => 'Delayed',
                default => 'Healthy',
            };

            $card = [
                'key' => $key,
                'name' => $row?->name ?? $key,
                'status' => $status,
                'interval_minutes' => $row?->interval_minutes,
                'last_attempt_at' => $row?->last_attempt_at?->toIso8601String(),
                'last_success_at' => $success?->toIso8601String(),
                'next_run_estimate_at' => $row?->last_attempt_at?->copy()->addMinutes((int) $row->interval_minutes)->toIso8601String(),
                'last_fetched_count' => $row?->last_fetched_count,
                'last_processed_count' => $row?->last_processed_count,
                'last_run_returned_zero' => $row !== null && $row->last_fetched_count === 0,
                'no_events_24h' => $key !== 'open_meteo' && $this->zeroForADay($key, $now),
                'last_error' => $row?->last_error,
                'consecutive_failures' => (int) ($row?->consecutive_failures ?? 0),
                'last_duration_ms' => $latest?->duration_ms,
                'latest_run' => $latest === null ? null : $this->describeRun($latest, $now),
            ];

            if ($key === 'open_meteo') {
                $card['latest_observation_at'] = CapitalWeather::query()->max('observed_at') === null
                    ? null : Carbon::parse(CapitalWeather::query()->max('observed_at'))->toIso8601String();
                $card['capitals_with_data'] = CapitalWeather::query()->count();
                $card['capitals_total'] = 11;
                $card['oldest_observation_at'] = CapitalWeather::query()->min('observed_at') === null
                    ? null : Carbon::parse(CapitalWeather::query()->min('observed_at'))->toIso8601String();
                $card['capitals_outdated'] = CapitalWeather::query()->where('observed_at', '<', $now->copy()->subMinutes($delayed))->count();
            }

            $cards[] = $card;
        }

        // The last runs of EACH source, so the five-minute USGS runs cannot push the weather history off the list.
        $runs = collect(array_keys($this->thresholds()))
            ->flatMap(fn (string $key) => SyncRun::query()->with('requestedBy')->where('source_key', $key)->latest('id')->limit(self::HISTORY_PER_SOURCE)->get())
            ->sortByDesc('id')
            ->values()
            ->map(fn (SyncRun $run): array => $this->describeRun($run, $now))
            ->all();

        return ['sources' => $cards, 'runs' => $runs];
    }

    /**
     * Every successful run of the source in the last 24 h returned nothing (and there was at least one).
     */
    private function zeroForADay(string $key, Carbon $now): bool
    {
        $recent = SyncRun::query()->where('source_key', $key)->where('status', 'success')->where('finished_at', '>=', $now->copy()->subDay());

        return $recent->count() > 0 && (clone $recent)->where('fetched_count', '>', 0)->doesntExist();
    }

    /**
     * @return array<string, mixed>
     */
    private function describeRun(SyncRun $run, Carbon $now): array
    {
        $state = match (true) {
            $run->status === 'success' && $run->warning_message !== null => 'success_with_warnings',
            $run->status === 'running' && $run->started_at !== null && $run->started_at->diffInSeconds($now, true) > self::ABANDONED_SECONDS => 'abandoned',
            default => $run->status,
        };

        return [
            'id' => $run->id,
            'source_key' => $run->source_key,
            'state' => $state,
            'status' => $run->status,
            'trigger' => $run->trigger,
            'request_uuid' => $run->request_uuid,
            'requested_by' => $run->requestedBy?->name,
            'queued_at' => $run->queued_at?->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'duration_ms' => $run->duration_ms,
            'http_status' => $run->http_status,
            'fetched_count' => $run->fetched_count,
            'created_count' => $run->created_count,
            'updated_count' => $run->updated_count,
            'skipped_count' => $run->skipped_count,
            'error_message' => $run->error_message,
            'warning_message' => $run->warning_message,
            'worker_warning' => $run->status === 'queued' && $run->queued_at !== null
                && $run->queued_at->diffInSeconds($now, true) > self::WORKER_WARNING_SECONDS,
        ];
    }

    /**
     * Keyed gdacs, usgs and weather (the keys the front end already uses).
     *
     * @return array{sources: array<string, array<string, mixed>>, data_as_of: string|null}
     */
    public function snapshot(): array
    {
        $rows = DataSource::query()->get()->keyBy('key');

        $sources = [
            'gdacs' => $this->describe('gdacs', $rows->get('gdacs'), DisasterEvent::query()->where('source', 'gdacs')->max('occurred_at')),
            'usgs' => $this->describe('usgs', $rows->get('usgs'), DisasterEvent::query()->where('source', 'usgs')->max('occurred_at')),
            'weather' => $this->describe('open_meteo', $rows->get('open_meteo'), CapitalWeather::query()->max('observed_at')),
        ];

        $sources['weather']['cities_count'] = CapitalWeather::query()->count();

        $successTimes = collect(['gdacs', 'usgs'])
            ->map(fn (string $key) => $rows->get($key)?->last_success_at)
            ->filter();

        return [
            'sources' => $sources,
            'data_as_of' => $successTimes->isEmpty() ? null : $successTimes->min()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(string $key, ?DataSource $row, mixed $latest): array
    {
        [$label, $name, $frequency, $documentation] = self::DOCS[$key];

        $interval = max(1, (int) ($row?->interval_minutes ?? 15));
        $lastSuccess = $row?->last_success_at;
        $now = Carbon::now();

        // The event sources are warned about after 2 x their interval. Weather uses the shared config policy:
        // warned (Delayed) after delayed_after_minutes, Stale after stale_after_minutes.
        $warnAfter = $key === 'open_meteo'
            ? (int) config('disaster_sources.sources.open_meteo.delayed_after_minutes', 60)
            : $interval * 2;
        $staleAfter = $key === 'open_meteo'
            ? (int) config('disaster_sources.sources.open_meteo.stale_after_minutes', 180)
            : null;

        $isFresh = $lastSuccess !== null && $lastSuccess->greaterThanOrEqualTo($now->copy()->subMinutes($warnAfter));
        $isStale = $lastSuccess !== null && ! $isFresh;
        $failed = $row?->last_status === 'failed';

        $status = match (true) {
            $lastSuccess === null => 'Unavailable',
            $isFresh => 'Live',
            $staleAfter !== null && $lastSuccess->greaterThanOrEqualTo($now->copy()->subMinutes($staleAfter)) => 'Delayed',
            default => 'Stale',
        };

        $connection = match (true) {
            $row === null || $row->last_attempt_at === null => 'Never synced',
            $failed => 'Last sync failed',
            default => 'Last sync succeeded',
        };

        return [
            'source' => $label,
            'name' => $name,
            'status' => $status,
            'freshness_status' => $status === 'Live' ? 'Fresh' : (in_array($status, ['Stale', 'Delayed'], true) ? 'Stale' : 'Unavailable'),
            'connection_status' => $connection,
            'last_successful_fetch' => $lastSuccess?->toIso8601String(),
            'latest_event_time' => $latest === null ? null : Carbon::parse($latest)->toIso8601String(),
            'total_features_fetched' => $row?->last_fetched_count,
            'sea_events_count' => $row?->last_processed_count,
            'cache_ttl_seconds' => DashboardCache::PAYLOAD_TTL,
            'provider_update_frequency' => $frequency,
            'frontend_poll_interval_seconds' => 60,
            'is_fresh' => $isFresh,
            'is_stale' => $isStale,
            'error' => $failed ? $row?->last_error : null,
            'documentation_url' => $documentation,
        ];
    }
}
