<?php

namespace App\Services\Disaster\Sync;

use Illuminate\Support\Facades\Date;

/**
 * The total time one sync may spend fetching (HLD v1.4 §28.9).
 *
 * No new request and no retry wait may start once it has passed. A request already in flight can
 * still take up to one attempt timeout, which `config('disaster_sources.sync')` accounts for.
 */
final class SyncDeadline
{
    private float $endsAt;

    public function __construct(private int $seconds)
    {
        $this->endsAt = Date::now()->getPreciseTimestamp(6) / 1_000_000 + $seconds;
    }

    public function seconds(): int
    {
        return $this->seconds;
    }

    public function remaining(): float
    {
        return max(0.0, $this->endsAt - Date::now()->getPreciseTimestamp(6) / 1_000_000);
    }

    public function expired(): bool
    {
        return $this->remaining() <= 0.0;
    }
}
