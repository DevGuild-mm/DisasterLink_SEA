<?php

namespace App\Services\Disaster\Sync;

use RuntimeException;
use Throwable;

/**
 * A source could not be fetched or returned an unusable response.
 */
class SourceFetchException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $httpStatus = null, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
