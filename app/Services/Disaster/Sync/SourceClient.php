<?php

namespace App\Services\Disaster\Sync;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fetch and normalise one external source. No caching, no database writes.
 */
abstract class SourceClient
{
    abstract public function key(): string;

    /**
     * @throws SourceFetchException when the source is unreachable or returns an unusable response
     */
    abstract public function fetch(): FetchResult;

    /**
     * Send a GET request with TLS verification on, timeouts and limited retries (connection errors, 429, 5xx).
     *
     * @param  array<string, mixed>  $query
     *
     * @throws SourceFetchException
     */
    protected function send(array $query = []): Response
    {
        try {
            $response = Http::timeout((int) $this->config('timeout', 15))
                ->connectTimeout((int) $this->config('connect_timeout', 5))
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => (string) config('disaster_sources.user_agent'),
                ])
                ->retry(
                    (array) $this->config('retry_delays_ms', []),
                    when: fn (Throwable $e): bool => $e instanceof ConnectionException
                        || ($e instanceof RequestException
                            && ($e->response->status() === 429 || $e->response->serverError())),
                    throw: false,
                )
                ->get((string) $this->config('url'), $query);
        } catch (ConnectionException $e) {
            throw new SourceFetchException('Could not connect to '.$this->key().'.', null, $e);
        }

        if (! $response->successful()) {
            throw new SourceFetchException($this->key().' returned HTTP '.$response->status().'.', $response->status());
        }

        return $response;
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
