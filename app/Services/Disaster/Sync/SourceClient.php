<?php

namespace App\Services\Disaster\Sync;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fetch and normalise one external source. No caching, no database writes.
 */
abstract class SourceClient
{
    private ?SyncDeadline $deadline = null;

    abstract public function key(): string;

    /**
     * Bound the total time of the next fetch, or pass null to remove the bound.
     */
    public function useDeadline(?SyncDeadline $deadline): void
    {
        $this->deadline = $deadline;
    }

    /**
     * @throws SourceFetchException when the source is unreachable or returns an unusable response
     */
    abstract public function fetch(): FetchResult;

    /**
     * Send a GET request with TLS verification on, timeouts and limited retries.
     *
     * Retried: connection errors, HTTP 5xx and HTTP 429. For a 429 the Retry-After header is honoured
     * (seconds or an HTTP date), unless it exceeds `max_retry_after_seconds`, in which case it is not retried.
     * Other waits use `retry_delays_ms`.
     *
     * With a deadline set, no request starts after it, and a retry starts only if its wait leaves time
     * before the deadline. Each attempt's timeout is capped by the time that remains.
     *
     * @param  array<string, mixed>  $query
     *
     * @throws SourceFetchException
     */
    protected function send(array $query = []): Response
    {
        /** @var array<int, int> $delays */
        $delays = array_values((array) $this->config('retry_delays_ms', []));

        $this->guardDeadline();

        $failures = 0;
        $stoppedByDeadline = false;

        try {
            $response = Http::timeout($this->capped((float) $this->config('timeout', 15)))
                ->connectTimeout($this->capped((float) $this->config('connect_timeout', 5)))
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => (string) config('disaster_sources.user_agent'),
                ])
                ->retry(
                    count($delays) + 1,
                    fn (int $attempt, mixed $e): int => $this->retryDelayMs($attempt, $e, $delays),
                    when: function (Throwable $e) use ($delays, &$failures, &$stoppedByDeadline): bool {
                        $failures++;

                        if (! $this->shouldRetry($e)) {
                            return false;
                        }

                        if ($this->deadline !== null && $this->retryDelayMs($failures, $e, $delays) / 1000 >= $this->deadline->remaining()) {
                            $stoppedByDeadline = true;

                            return false;
                        }

                        return true;
                    },
                    throw: false,
                )
                ->get((string) $this->config('url'), $query);
        } catch (ConnectionException $e) {
            throw new SourceFetchException(
                $stoppedByDeadline ? $this->deadlineMessage() : 'Could not connect to '.$this->key().'.',
                null,
                $e,
            );
        }

        if (! $response->successful() && $stoppedByDeadline) {
            throw new SourceFetchException($this->deadlineMessage(), $response->status());
        }

        if (! $response->successful()) {
            $retryAfter = $response->status() === 429 ? $this->retryAfterSeconds($response) : null;

            throw new SourceFetchException(
                $this->key().' returned HTTP '.$response->status().($retryAfter !== null ? " (Retry-After {$retryAfter}s)" : '').'.',
                $response->status(),
            );
        }

        return $response;
    }

    private function guardDeadline(): void
    {
        if ($this->deadline?->expired()) {
            throw new SourceFetchException($this->deadlineMessage());
        }
    }

    private function deadlineMessage(): string
    {
        return $this->key().' sync stopped: the total time limit of '.$this->deadline?->seconds().' s was reached.';
    }

    /**
     * An attempt timeout, never longer than the time left before the deadline (at least 1 s).
     */
    private function capped(float $seconds): float
    {
        return $this->deadline === null ? $seconds : max(1.0, min($seconds, $this->deadline->remaining()));
    }

    private function shouldRetry(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if (! $e instanceof RequestException) {
            return false;
        }

        if ($e->response->status() === 429) {
            $retryAfter = $this->retryAfterSeconds($e->response);

            return $retryAfter === null || $retryAfter <= $this->maxRetryAfterSeconds();
        }

        return $e->response->serverError();
    }

    /**
     * @param  array<int, int>  $delays
     */
    private function retryDelayMs(int $attempt, mixed $exception, array $delays): int
    {
        if ($exception instanceof RequestException && $exception->response->status() === 429) {
            $retryAfter = $this->retryAfterSeconds($exception->response);

            if ($retryAfter !== null) {
                return min($retryAfter, $this->maxRetryAfterSeconds()) * 1000;
            }
        }

        return $delays[$attempt - 1] ?? ($delays === [] ? 0 : end($delays));
    }

    /**
     * Retry-After as whole seconds from now. Accepts delay-seconds or an HTTP date; null when absent or invalid.
     */
    private function retryAfterSeconds(Response $response): ?int
    {
        $header = trim($response->header('Retry-After'));

        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return (int) $header;
        }

        $timestamp = strtotime($header);

        return $timestamp === false ? null : max(0, $timestamp - Date::now()->getTimestamp());
    }

    private function maxRetryAfterSeconds(): int
    {
        return (int) config('disaster_sources.max_retry_after_seconds', 30);
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        return config("disaster_sources.sources.{$this->key()}.{$key}", $default);
    }

    /**
     * @param  array<string, mixed>  $feature
     * @return array{float, float}|null [latitude, longitude] of a GeoJSON point, or null when invalid
     */
    protected function point(array $feature): ?array
    {
        $coordinates = $feature['geometry']['coordinates'] ?? null;

        if (! is_array($coordinates) || count($coordinates) < 2 || ! is_numeric($coordinates[0]) || ! is_numeric($coordinates[1])) {
            return null;
        }

        $lng = (float) $coordinates[0];
        $lat = (float) $coordinates[1];

        if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
            return null;
        }

        return [$lat, $lng];
    }

    protected function inRegion(float $lat, float $lng): bool
    {
        $region = config('disaster_sources.region');

        return $lat >= $region['min_lat'] && $lat <= $region['max_lat']
            && $lng >= $region['min_lng'] && $lng <= $region['max_lng'];
    }

    protected function safeUrl(mixed $url): ?string
    {
        return is_string($url) ? SafeUrl::validate($url, (array) config('disaster_sources.allowed_url_hosts')) : null;
    }

    /**
     * Plain text: tags stripped, entities decoded, whitespace collapsed, length limited.
     */
    protected function cleanText(mixed $value, int $limit): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5)));

        return $text === '' ? null : Str::limit($text, $limit, '');
    }

    /**
     * A deterministic ID for the rare record that has none. Such events are never alert-eligible.
     */
    protected function syntheticId(string $eventType, float $lat, float $lng, CarbonImmutable $occurredAt): string
    {
        return 'syn:'.sha1(implode('|', [
            $this->key(),
            $eventType,
            round($lat, 2),
            round($lng, 2),
            $occurredAt->utc()->format('Y-m-d H:i'),
        ]));
    }
}
