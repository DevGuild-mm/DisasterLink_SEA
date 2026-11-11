<?php

namespace App\Services\Disaster\Weather;

use App\Models\CapitalWeather;
use App\Models\Country;
use App\Models\DataSource;
use App\Models\SyncRun;
use App\Services\Disaster\Dashboard\DashboardCache;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * One batched Open-Meteo request for the active countries' capitals, stored in `capital_weather`
 * (HLD §14). Page requests read that table (through the cache) and never call Open-Meteo.
 */
class WeatherRefreshService
{
    private const SOURCE = 'open_meteo';

    /** WMO weather interpretation codes. */
    private const WMO_CODES = [
        0 => ['Clear sky', 'sun'], 1 => ['Mainly clear', 'sun-dim'], 2 => ['Partly cloudy', 'cloud-sun'], 3 => ['Overcast', 'cloud'],
        45 => ['Fog', 'cloud-fog'], 48 => ['Depositing rime fog', 'cloud-fog'],
        51 => ['Light drizzle', 'cloud-drizzle'], 53 => ['Moderate drizzle', 'cloud-drizzle'], 55 => ['Dense drizzle', 'cloud-rain'],
        61 => ['Slight rain', 'cloud-rain'], 63 => ['Moderate rain', 'cloud-rain'], 65 => ['Heavy rain', 'cloud-heavy-rain'],
        71 => ['Slight snow', 'snowflake'], 73 => ['Moderate snow', 'snowflake'], 75 => ['Heavy snow', 'snowflake'],
        80 => ['Slight rain showers', 'cloud-sun-rain'], 81 => ['Moderate rain showers', 'cloud-sun-rain'], 82 => ['Violent rain showers', 'cloud-lightning-rain'],
        95 => ['Thunderstorm', 'cloud-lightning'], 96 => ['Thunderstorm with slight hail', 'cloud-lightning'], 99 => ['Thunderstorm with heavy hail', 'cloud-lightning'],
    ];

    public function __construct(private DashboardCache $dashboardCache) {}

    /** A queued manual run that no worker claimed in this time is expired (same rule as the event sources). */
    private const QUEUE_EXPIRY_MINUTES = 10;

    /**
     * Refresh under the `sync:open_meteo` lock, the one the scheduler, the catch-up and the admin job all share.
     *
     * With `$queuedRunId` (the admin "Sync from sources" job) the queued row is claimed with one atomic update and
     * that same row records the run, so the trigger and the requesting admin stay on it.
     *
     * @return SyncRun|null null when another weather refresh holds the lock
     */
    public function refresh(?int $queuedRunId = null): ?SyncRun
    {
        $lock = $this->lock();

        if (! $lock->get()) {
            return null;
        }

        try {
            $this->sweepAbandoned();

            if ($queuedRunId === null) {
                return $this->run(null, 'scheduler');
            }

            $claimed = SyncRun::query()
                ->where('id', $queuedRunId)
                ->where('status', 'queued')
                ->where('queued_at', '>=', Carbon::now()->subMinutes(self::QUEUE_EXPIRY_MINUTES))
                ->update(['status' => 'running', 'started_at' => Carbon::now()]);

            if ($claimed === 0) {
                Log::info("Weather run {$queuedRunId} was not claimed (expired or already taken).");

                return SyncRun::query()->find($queuedRunId);
            }

            return $this->run(SyncRun::query()->findOrFail($queuedRunId), 'manual');
        } finally {
            $lock->release();
        }
    }

    /**
     * Refresh once when the scheduled runs were missed (the machine slept, the scheduler was stopped).
     *
     * The staleness check happens after the lock is taken, so a scheduled or admin refresh that finishes at the
     * same moment is seen as fresh and no second request is made.
     *
     * @return SyncRun|null null when no catch-up was needed or another refresh holds the lock
     */
    public function catchUp(): ?SyncRun
    {
        // Lock-free pre-check: when the data is fresh (the usual case, every minute) nothing else is touched.
        if (! $this->needsCatchUp()) {
            return null;
        }

        $lock = $this->lock();

        if (! $lock->get()) {
            return null;
        }

        try {
            // Checked again under the lock: a refresh that finished meanwhile makes this one unnecessary.
            if (! $this->needsCatchUp()) {
                return null;
            }

            $this->sweepAbandoned();

            return $this->run(null, 'catch_up');
        } finally {
            $lock->release();
        }
    }

    /**
     * The last success is older than the catch-up limit and no attempt happened within the retry window.
     */
    public function needsCatchUp(): bool
    {
        $source = DataSource::query()->where('key', self::SOURCE)->first();
        $now = Carbon::now();

        $staleAfter = (int) config('disaster_sources.sources.open_meteo.delayed_after_minutes', 60);
        $retryAfter = (int) config('disaster_sources.sources.open_meteo.catch_up_retry_minutes', 30);

        if ($source?->last_success_at !== null && $source->last_success_at->greaterThanOrEqualTo($now->copy()->subMinutes($staleAfter))) {
            return false;
        }

        return $source?->last_attempt_at === null || $source->last_attempt_at->lessThan($now->copy()->subMinutes($retryAfter));
    }

    private function lock(): Lock
    {
        return Cache::lock('sync:'.self::SOURCE, (int) config('disaster_sources.sync.lock_seconds', 300));
    }

    /**
     * Runs only while this process holds the lock, so no live weather run exists. Leftover `running` rows are
     * failed, and queued rows older than the expiry are failed (same rule as the event sources).
     */
    private function sweepAbandoned(): void
    {
        $now = Carbon::now();

        $abandoned = SyncRun::query()->where('source_key', self::SOURCE)->where('status', 'running')->update([
            'status' => 'failed',
            'error_message' => 'Abandoned: the process stopped before finishing.',
            'finished_at' => $now,
        ]);

        SyncRun::query()
            ->where('source_key', self::SOURCE)
            ->where('status', 'queued')
            ->where('queued_at', '<', $now->copy()->subMinutes(self::QUEUE_EXPIRY_MINUTES))
            ->update(['status' => 'failed', 'error_message' => 'Not picked up by a worker.', 'finished_at' => $now]);

        if ($abandoned > 0) {
            DataSource::query()->where('key', self::SOURCE)->where('last_status', 'running')->update(['last_status' => 'failed']);
        }
    }

    private function run(?SyncRun $claimed, string $trigger): SyncRun
    {
        $startedAt = $claimed?->started_at ?? Carbon::now();

        $source = DataSource::query()->firstOrCreate(['key' => self::SOURCE], [
            'name' => (string) config('disaster_sources.sources.open_meteo.name', 'Open-Meteo'),
            'pipeline' => 'weather',
            'interval_minutes' => (int) config('disaster_sources.sources.open_meteo.interval_minutes', 30),
        ]);

        $source->update(['last_attempt_at' => $startedAt, 'last_status' => 'running']);

        $run = $claimed ?? SyncRun::query()->create([
            'source_key' => self::SOURCE,
            'status' => 'running',
            'trigger' => $trigger,
            'started_at' => $startedAt,
        ]);
        $timer = hrtime(true);

        try {
            $countries = Country::query()->where('is_active', true)->orderBy('sort_order')->get();
            [$rows, $httpStatus, $warning] = $this->fetch($countries->all());
            $changed = $this->store($rows);
        } catch (Throwable $e) {
            return $this->fail($source, $run, $e, $this->durationMs($timer));
        }

        $run->update([
            'status' => 'success',
            'http_status' => $httpStatus,
            'fetched_count' => count($rows),
            'processed_count' => $changed,
            'updated_count' => $changed,
            'warning_message' => $warning,
            'duration_ms' => $this->durationMs($timer),
            'finished_at' => Carbon::now(),
        ]);

        $source->update([
            'last_success_at' => Carbon::now(),
            'last_status' => 'success',
            'last_error' => null,
            'consecutive_failures' => 0,
            'last_fetched_count' => count($rows),
            'last_processed_count' => $changed,
        ]);

        if ($changed > 0) {
            $this->dashboardCache->invalidateWeather();
        }

        return $run;
    }

    /**
     * @param  array<int, Country>  $countries
     * @return array{array<int, array<string, mixed>>, int, string|null} rows, HTTP status, and a warning when only some capitals were usable
     */
    private function fetch(array $countries): array
    {
        if ($countries === []) {
            throw new RuntimeException('No active countries to fetch weather for.');
        }

        $config = (array) config('disaster_sources.sources.open_meteo');
        $delays = array_values((array) ($config['retry_delays_ms'] ?? []));

        try {
            $response = Http::timeout((float) ($config['timeout'] ?? 15))
                ->connectTimeout((float) ($config['connect_timeout'] ?? 5))
                ->withHeaders(['Accept' => 'application/json', 'User-Agent' => (string) config('disaster_sources.user_agent')])
                ->retry(
                    count($delays) + 1,
                    fn (int $attempt): int => $delays[$attempt - 1] ?? 0,
                    when: fn (Throwable $e): bool => $e instanceof ConnectionException || ($e->response ?? null)?->serverError() === true,
                    throw: false,
                )
                ->get((string) $config['url'], [
                    'latitude' => implode(',', array_map(fn (Country $c): string => (string) (float) $c->capital_lat, $countries)),
                    'longitude' => implode(',', array_map(fn (Country $c): string => (string) (float) $c->capital_lng, $countries)),
                    'current' => 'temperature_2m,relative_humidity_2m,precipitation,weather_code,wind_speed_10m',
                    'daily' => 'temperature_2m_max,temperature_2m_min',
                    'timezone' => 'auto',
                    'forecast_days' => 1,
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Could not connect to Open-Meteo.');
        }

        if (! $response->successful()) {
            throw new OpenMeteoHttpException($response->status());
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException('Open-Meteo returned an invalid response.');
        }

        $items = array_is_list($data) ? $data : [$data];
        $now = Carbon::now();
        $rows = [];

        foreach ($countries as $index => $country) {
            $item = $items[$index] ?? null;
            $current = is_array($item) && is_array($item['current'] ?? null) ? $item['current'] : null;

            if ($current === null || ! isset($current['temperature_2m']) || ! is_numeric($current['temperature_2m'])) {
                continue;
            }

            $daily = is_array($item['daily'] ?? null) ? $item['daily'] : [];
            $code = is_numeric($current['weather_code'] ?? null) ? (int) $current['weather_code'] : null;
            [$condition, $icon] = self::WMO_CODES[$code] ?? ['Unknown', 'cloud'];

            $rows[] = [
                'country_id' => $country->id,
                'temperature_c' => round((float) $current['temperature_2m'], 2),
                'weather_code' => $code,
                'condition' => $condition,
                'icon' => $icon,
                'precipitation_mm' => is_numeric($current['precipitation'] ?? null) ? round((float) $current['precipitation'], 2) : null,
                'wind_speed_kmh' => is_numeric($current['wind_speed_10m'] ?? null) ? round((float) $current['wind_speed_10m'], 2) : null,
                'humidity_pct' => is_numeric($current['relative_humidity_2m'] ?? null) ? min(100, max(0, (int) $current['relative_humidity_2m'])) : null,
                'forecast_min_c' => is_numeric($daily['temperature_2m_min'][0] ?? null) ? round((float) $daily['temperature_2m_min'][0], 2) : null,
                'forecast_max_c' => is_numeric($daily['temperature_2m_max'][0] ?? null) ? round((float) $daily['temperature_2m_max'][0], 2) : null,
                'observed_at' => $this->observedAt($current['time'] ?? null, $item['utc_offset_seconds'] ?? 0),
                'fetched_at' => $now,
            ];
        }

        if ($rows === []) {
            throw new RuntimeException('Open-Meteo returned no usable capital observations.');
        }

        // A partial answer is still a success: the usable observations are stored and the capitals without one keep
        // their previous observation (their `observed_at` shows how old it is). The run says so instead of passing
        // as a full refresh.
        $warning = count($rows) < count($countries)
            ? 'Partial response: usable observations for '.count($rows).' of '.count($countries).' capitals. The others kept their previous observations.'
            : null;

        return [$rows, $response->status(), $warning];
    }

    /**
     * Open-Meteo gives the local time of the location; store UTC.
     */
    private function observedAt(mixed $time, mixed $offsetSeconds): ?Carbon
    {
        if (! is_string($time)) {
            return null;
        }

        try {
            return Carbon::parse($time, 'UTC')->subSeconds((int) $offsetSeconds);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Upsert the snapshot and return how many capitals changed. `fetched_at` alone is not a change.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function store(array $rows): int
    {
        return DB::transaction(function () use ($rows): int {
            $existing = CapitalWeather::query()->whereIn('country_id', array_column($rows, 'country_id'))->get()->keyBy('country_id');
            $changed = 0;

            foreach ($rows as $row) {
                $current = $existing->get($row['country_id']);

                if ($current === null || $this->differs($current, $row)) {
                    $changed++;
                }

                CapitalWeather::query()->updateOrCreate(['country_id' => $row['country_id']], $row);
            }

            return $changed;
        });
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function differs(CapitalWeather $current, array $row): bool
    {
        foreach (['temperature_c', 'precipitation_mm', 'wind_speed_kmh', 'forecast_min_c', 'forecast_max_c'] as $column) {
            if (($current->{$column} === null) !== ($row[$column] === null)
                || ($row[$column] !== null && abs((float) $current->{$column} - (float) $row[$column]) >= 0.005)) {
                return true;
            }
        }

        return $current->weather_code !== $row['weather_code']
            || $current->humidity_pct !== $row['humidity_pct']
            || $current->observed_at?->toIso8601String() !== $row['observed_at']?->toIso8601String();
    }

    private function fail(DataSource $source, SyncRun $run, Throwable $e, int $durationMs): SyncRun
    {
        $message = Str::limit($e->getMessage(), 500, '');

        Log::warning("Weather refresh failed: {$message}");

        $run->update([
            'status' => 'failed',
            'http_status' => $e instanceof OpenMeteoHttpException ? $e->httpStatus : null,
            'error_message' => $message,
            'duration_ms' => $durationMs,
            'finished_at' => Carbon::now(),
        ]);
        $source->update(['last_status' => 'failed', 'last_error' => $message, 'consecutive_failures' => $source->consecutive_failures + 1]);

        return $run;
    }

    private function durationMs(int $timer): int
    {
        return (int) round((hrtime(true) - $timer) / 1_000_000);
    }
}
