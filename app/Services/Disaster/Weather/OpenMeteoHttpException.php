<?php

namespace App\Services\Disaster\Weather;

use RuntimeException;

/**
 * Open-Meteo answered with a non-success HTTP status. Only this exception records an `http_status` on the run, so an
 * unrelated exception whose code happens to look like a status is never mistaken for one.
 */
class OpenMeteoHttpException extends RuntimeException
{
    public function __construct(public readonly int $httpStatus)
    {
        parent::__construct("Open-Meteo returned HTTP {$httpStatus}.");
    }
}
