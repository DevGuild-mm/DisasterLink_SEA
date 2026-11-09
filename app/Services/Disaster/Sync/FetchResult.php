<?php

namespace App\Services\Disaster\Sync;

/**
 * What one source returned: the usable events plus counts for the sync run.
 */
final readonly class FetchResult
{
    /**
     * @param  array<int, NormalizedEvent>  $events
     */
    public function __construct(
        public array $events,
        public int $fetchedCount,
        public int $skippedCount,
        public ?int $httpStatus,
    ) {}
}
