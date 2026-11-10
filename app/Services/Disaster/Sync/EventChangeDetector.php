<?php

namespace App\Services\Disaster\Sync;

use App\Models\DisasterEvent;

/**
 * Decides whether a re-fetched event differs from the stored one in a way that matters.
 *
 * Meaningful: every field the UI shows or that country resolution, alert eligibility or later
 * notifications read, plus `source_meta`, which carries the source country code (`iso3`), the
 * `synthetic_id` flag that blocks alerts, the affected countries and the USGS status/tsunami/PAGER
 * values. Country changes are detected separately by the sync after resolution.
 *
 * Not meaningful: `last_seen_at`, `last_synced_at`, `updated_at`, `raw_payload` (and the row's own
 * `first_seen_at` / `created_at`). A repeat sync rewrites these every time.
 */
final class EventChangeDetector
{
    /**
     * Column => comparison kind (and, for numbers, the stored decimal scale).
     *
     * @var array<string, array{0: string, 1?: int}>
     */
    private const COLUMNS = [
        'episode_id' => ['string'],
        'event_type' => ['string'],
        'title' => ['string'],
        'description' => ['string'],
        'location_text' => ['string'],
        'alert_level' => ['string'],
        'alert_level_derived' => ['bool'],
        'magnitude' => ['number', 2],
        'severity_value' => ['number', 3],
        'severity_unit' => ['string'],
        'depth_km' => ['number', 2],
        'latitude' => ['number', 6],
        'longitude' => ['number', 6],
        'occurred_at' => ['datetime'],
        'ended_at' => ['datetime'],
        'source_updated_at' => ['datetime'],
        'source_url' => ['string'],
        'source_meta' => ['json'],
    ];

    /**
     * Columns to load from the database to compare against.
     *
     * @return array<int, string>
     */
    public static function columns(): array
    {
        return ['external_id', 'country_id', 'country_status', ...array_keys(self::COLUMNS)];
    }

    /**
     * @param  array<string, mixed>  $row  a NormalizedEvent::toRow() array
     */
    public static function differs(array $row, DisasterEvent $stored): bool
    {
        foreach (self::COLUMNS as $column => $kind) {
            if (self::normalise($row[$column] ?? null, $kind) !== self::normalise($stored->getRawOriginal($column), $kind)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{0: string, 1?: int}  $kind
     */
    private static function normalise(mixed $value, array $kind): string|bool|null
    {
        if ($value === null) {
            return null;
        }

        return match ($kind[0]) {
            'bool' => (bool) $value,
            'number' => number_format((float) $value, $kind[1] ?? 6, '.', ''),
            'datetime' => substr((string) $value, 0, 19),
            'json' => self::canonicalJson($value),
            default => (string) $value,
        };
    }

    private static function canonicalJson(mixed $value): string
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return (string) json_encode(self::sortKeys($decoded));
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::sortKeys(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
