<?php

namespace App\Services\Disaster\Dashboard;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The only place that knows the dashboard cache keys, TTLs and invalidation (HLD v1.4 §28.5, §28.7).
 *
 * Cache-aside over MySQL. A miss rebuilds from MySQL and never calls an external API. A generation counter
 * is part of the payload key, so a reader that started before an invalidation can only write under the old
 * generation, which no later request reads.
 */
class DashboardCache
{
    public const GENERATION_KEY = 'dashboard:gen';

    public const PAYLOAD_TTL = 300;

    public const COUNTRY_ALERTS_TTL = 60;

    public const WEATHER_KEY = 'weather:capitals:v1';

    public const WEATHER_TTL = 2100;

    private const REBUILD_LOCK = 'dashboard:rebuild';

    private const REBUILD_LOCK_SECONDS = 10;

    private const REBUILD_WAIT_SECONDS = 3;

    private const RELOAD_WINDOW = 'dashboard:reload-window';

    private const RELOAD_WINDOW_SECONDS = 10;

    public function generation(): int
    {
        return (int) Cache::get(self::GENERATION_KEY, 1);
    }

    public function payloadKey(?int $generation = null): string
    {
        return 'dashboard:current:v1:g'.($generation ?? $this->generation());
    }

    /**
     * Normal read: a hit returns the cached payload, a miss rebuilds it from MySQL and stores it.
     * If the rebuild lock cannot be taken in time, the payload is built and returned without being stored.
     * If the cache store is unavailable, the payload is built directly.
     *
     * @param  Closure(): array<string, mixed>  $build
     * @return array<string, mixed>
     */
    public function remember(Closure $build): array
    {
        $failure = null;

        $guarded = function () use ($build, &$failure): array {
            try {
                return $build();
            } catch (Throwable $e) {
                $failure = $e;

                throw $e;
            }
        };

        try {
            $key = $this->payloadKey();

            $cached = Cache::get($key);

            if (is_array($cached)) {
                return $cached;
            }

            return $this->rebuild($key, $guarded);
        } catch (Throwable $e) {
            if ($failure !== null) {
                Log::error('Dashboard rebuild failed, nothing was cached: '.$failure->getMessage());

                throw $failure;
            }

            Log::warning('Dashboard cache unavailable, building from MySQL: '.$e->getMessage());

            return $build();
        }
    }

    /**
     * Public "Reload data": at most one rebuild per window. Concurrent clicks inside the window get the normal
     * cache-aside result. Reads MySQL only.
     *
     * @param  Closure(): array<string, mixed>  $build
     * @return array<string, mixed>
     */
    public function reload(Closure $build): array
    {
        try {
            if (Cache::add(self::RELOAD_WINDOW, 1, self::RELOAD_WINDOW_SECONDS)) {
                $this->bump();
            }
        } catch (Throwable $e) {
            Log::warning('Dashboard reload could not use the cache: '.$e->getMessage());
        }

        return $this->remember($build);
    }

    /**
     * Called after a sync transaction has committed and something relevant changed. Never before the commit,
     * or a reader could cache pre-commit data.
     *
     * @param  array<int, int>  $countryIds  countries whose events changed (old and new)
     */
    public function invalidateAfterSync(array $countryIds = []): void
    {
        try {
            $this->bump();

            foreach (array_unique($countryIds) as $countryId) {
                Cache::forget($this->countryAlertsKey($countryId));
            }
        } catch (Throwable $e) {
            Log::warning('Dashboard cache invalidation failed: '.$e->getMessage());
        }
    }

    /**
     * Capital weather snapshot: cache first, MySQL on a miss.
     *
     * @param  Closure(): array<int, array<string, mixed>>  $build
     * @return array<int, array<string, mixed>>
     */
    public function weather(Closure $build): array
    {
        try {
            return Cache::remember(self::WEATHER_KEY, self::WEATHER_TTL, $build);
        } catch (Throwable $e) {
            Log::warning('Weather cache unavailable, reading MySQL: '.$e->getMessage());

            return $build();
        }
    }

    /**
     * Called by the weather refresh after it changed at least one row.
     */
    public function invalidateWeather(): void
    {
        try {
            Cache::forget(self::WEATHER_KEY);
            $this->bump();
        } catch (Throwable $e) {
            Log::warning('Weather cache invalidation failed: '.$e->getMessage());
        }
    }

    public function countryAlertsKey(int $countryId): string
    {
        return "country-alerts:{$countryId}:v1";
    }

    private function bump(): void
    {
        Cache::add(self::GENERATION_KEY, 1);
        Cache::increment(self::GENERATION_KEY);
    }

    /**
     * @param  Closure(): array<string, mixed>  $build
     * @return array<string, mixed>
     */
    private function rebuild(string $key, Closure $build): array
    {
        $lock = Cache::lock(self::REBUILD_LOCK, self::REBUILD_LOCK_SECONDS);

        try {
            $lock->block(self::REBUILD_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            return $build();
        }

        try {
            $cached = Cache::get($key);

            if (is_array($cached)) {
                return $cached;
            }

            $payload = $build();

            Cache::put($key, $payload, self::PAYLOAD_TTL);

            return $payload;
        } finally {
            $lock->release();
        }
    }
}
