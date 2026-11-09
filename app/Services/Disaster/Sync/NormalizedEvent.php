<?php

namespace App\Services\Disaster\Sync;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A source event in the shape stored in `disaster_events` (HLD §10.4). No cache, no persistence.
 */
final readonly class NormalizedEvent
{
    /**
     * @param  array<string, mixed>  $sourceMeta
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public string $source,
        public string $externalId,
        public ?string $episodeId,
        public string $eventType,
        public string $title,
        public ?string $description,
        public ?string $locationText,
        public ?string $alertLevel,
        public bool $alertLevelDerived,
        public ?float $magnitude,
        public ?float $severityValue,
        public ?string $severityUnit,
        public ?float $depthKm,
        public ?float $latitude,
        public ?float $longitude,
        public CarbonImmutable $occurredAt,
        public ?CarbonImmutable $endedAt,
        public ?CarbonImmutable $sourceUpdatedAt,
        public ?string $sourceUrl,
        public array $sourceMeta,
        public array $rawPayload,
    ) {}

    /**
     * Columns that a repeat sync may overwrite. Country, alert and first_seen fields are never touched.
     *
     * @return array<int, string>
     */
    public static function updatableColumns(): array
    {
        return [
            'episode_id', 'event_type', 'title', 'description', 'location_text', 'alert_level',
            'alert_level_derived', 'magnitude', 'severity_value', 'severity_unit', 'depth_km',
            'latitude', 'longitude', 'occurred_at', 'ended_at', 'source_updated_at', 'source_url',
            'source_meta', 'raw_payload', 'last_seen_at', 'last_synced_at', 'updated_at',
        ];
    }

    /**
     * A row ready for `upsert()`. New rows get first_seen_at and created_at; updates ignore them.
     *
     * @return array<string, mixed>
     */
    public function toRow(CarbonInterface $now): array
    {
        $format = fn (?CarbonInterface $date): ?string => $date?->utc()->toDateTimeString();
        $stamp = $format($now);

        return [
            'source' => $this->source,
            'external_id' => $this->externalId,
            'episode_id' => $this->episodeId,
            'event_type' => $this->eventType,
            'title' => $this->title,
            'description' => $this->description,
            'location_text' => $this->locationText,
            'alert_level' => $this->alertLevel,
            'alert_level_derived' => $this->alertLevelDerived,
            'magnitude' => $this->magnitude,
            'severity_value' => $this->severityValue,
            'severity_unit' => $this->severityUnit,
            'depth_km' => $this->depthKm,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'occurred_at' => $format($this->occurredAt),
            'ended_at' => $format($this->endedAt),
            'source_updated_at' => $format($this->sourceUpdatedAt),
            'source_url' => $this->sourceUrl,
            'source_meta' => json_encode($this->sourceMeta),
            'raw_payload' => json_encode($this->rawPayload),
            'first_seen_at' => $stamp,
            'last_seen_at' => $stamp,
            'last_synced_at' => $stamp,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ];
    }
}
