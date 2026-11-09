<?php

namespace App\Services\Disaster\Sync;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Fetches GDACS events (last 7 days) and normalises them. Country is not decided here.
 */
class GdacsClient extends SourceClient
{
    private const EVENT_TYPES = [
        'EQ' => 'earthquake',
        'TC' => 'cyclone',
        'FL' => 'flood',
        'VO' => 'volcano',
        'WF' => 'wildfire',
        'DR' => 'drought',
    ];

    public function key(): string
    {
        return 'gdacs';
    }

    public function fetch(): FetchResult
    {
        $now = CarbonImmutable::now('UTC');

        $response = $this->send([
            'eventlist' => $this->config('event_types'),
            'fromdate' => $now->subDays((int) $this->config('lookback_days', 7))->toDateString(),
            'todate' => $now->toDateString(),
        ]);

        // GDACS answers 204 / an empty body when nothing matches: a successful, empty run.
        if ($response->status() === 204 || trim($response->body()) === '') {
            return new FetchResult([], 0, 0, $response->status());
        }

        $data = $response->json();

        if (! is_array($data) || ! isset($data['features']) || ! is_array($data['features'])) {
            throw new SourceFetchException('GDACS returned an unexpected response shape.', $response->status());
        }

        $events = [];
        $skipped = 0;

        foreach ($data['features'] as $feature) {
            $event = is_array($feature) ? $this->normalize($feature) : null;

            if ($event === null) {
                $skipped++;

                continue;
            }

            $events[] = $event;
        }

        return new FetchResult($events, count($data['features']), $skipped, $response->status());
    }

    /**
     * @param  array<string, mixed>  $feature
     */
    private function normalize(array $feature): ?NormalizedEvent
    {
        $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];

        $typeCode = strtoupper(trim((string) ($props['eventtype'] ?? '')));

        if (! isset(self::EVENT_TYPES[$typeCode])) {
            return null;
        }

        $point = $this->point($feature);
        $iso3 = strtoupper(trim((string) ($props['iso3'] ?? '')));
        $affected = $this->affectedCountries($props['affectedcountries'] ?? null);

        if (! $this->isRelevantToRegion($point, $iso3, $affected)) {
            return null;
        }

        $occurredAt = $this->parseTime($props['fromdate'] ?? null);

        if ($occurredAt === null) {
            return null;
        }

        $eventId = trim((string) ($props['eventid'] ?? ''));
        $synthetic = $eventId === '';

        if ($synthetic) {
            if ($point === null) {
                return null;
            }
            $externalId = $this->syntheticId($typeCode, $point[0], $point[1], $occurredAt);
        } else {
            $externalId = $typeCode.':'.$eventId;
        }

        $episodeId = trim((string) ($props['episodeid'] ?? ''));
        $alert = ucfirst(strtolower(trim((string) ($props['alertlevel'] ?? ''))));
        $severity = is_array($props['severitydata'] ?? null) ? $props['severitydata'] : [];
        $severityValue = isset($severity['severity']) && is_numeric($severity['severity']) ? (float) $severity['severity'] : null;
        $severityText = $this->cleanText($severity['severitytext'] ?? null, 255);
        $eventType = self::EVENT_TYPES[$typeCode];

        $title = $this->cleanText($props['name'] ?? $props['eventname'] ?? null, 255)
            ?? ucfirst($eventType).' event';

        return new NormalizedEvent(
            source: 'gdacs',
            externalId: $externalId,
            episodeId: $episodeId !== '' ? $episodeId : null,
            eventType: $eventType,
            title: $title,
            description: $this->cleanText($props['description'] ?? $props['htmldescription'] ?? null, 2000),
            locationText: $this->cleanText($props['country'] ?? null, 255),
            alertLevel: in_array($alert, ['Green', 'Orange', 'Red'], true) ? $alert : null,
            alertLevelDerived: false,
            magnitude: $typeCode === 'EQ' && $severityValue !== null && $severityValue > 0 ? round($severityValue, 1) : null,
            severityValue: $severityValue,
            severityUnit: $this->cleanText($severity['severityunit'] ?? null, 30),
            depthKm: $typeCode === 'EQ' ? $this->depthFromText($severityText) : null,
            latitude: $point[0] ?? null,
            longitude: $point[1] ?? null,
            occurredAt: $occurredAt,
            endedAt: $this->endedAt($props),
            sourceUpdatedAt: $this->parseTime($props['datemodified'] ?? null),
            sourceUrl: $this->safeUrl($props['url']['report'] ?? null),
            sourceMeta: array_filter([
                'iso3' => $iso3 !== '' ? $iso3 : null,
                'affected_countries' => $affected !== [] ? $affected : null,
                'severity_text' => $severityText,
                'synthetic_id' => $synthetic ?: null,
            ], fn (mixed $value): bool => $value !== null),
            rawPayload: $feature,
        );
    }

    /**
     * Keep an event when GDACS names an ASEAN country, or the point is inside the region box.
     * The final country is decided later by CountryResolver; this only avoids storing the whole world.
     *
     * @param  array{float, float}|null  $point
     * @param  array<int, array{iso3: string, name: string}>  $affected
     */
    private function isRelevantToRegion(?array $point, string $iso3, array $affected): bool
    {
        $asean = (array) config('disaster_sources.asean_iso3');

        if (in_array($iso3, $asean, true)) {
            return true;
        }

        foreach ($affected as $country) {
            if (in_array($country['iso3'], $asean, true)) {
                return true;
            }
        }

        return $point !== null && $this->inRegion($point[0], $point[1]);
    }

    /**
     * @return array<int, array{iso3: string, name: string}>
     */
    private function affectedCountries(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $countries = [];

        foreach ($raw as $country) {
            if (is_array($country) && ! empty($country['iso3'])) {
                $countries[] = [
                    'iso3' => strtoupper(trim((string) $country['iso3'])),
                    'name' => (string) ($this->cleanText($country['countryname'] ?? '', 100) ?? ''),
                ];
            }
        }

        return $countries;
    }

    /**
     * GDACS marks finished events with iscurrent = false; todate is then the end time.
     * [GDACS field semantics unverified]
     *
     * @param  array<string, mixed>  $props
     */
    private function endedAt(array $props): ?CarbonImmutable
    {
        $isCurrent = strtolower(trim((string) ($props['iscurrent'] ?? 'true')));

        return $isCurrent === 'false' ? $this->parseTime($props['todate'] ?? null) : null;
    }

    private function parseTime(mixed $raw): ?CarbonImmutable
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw, 'UTC')->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private function depthFromText(?string $text): ?float
    {
        return $text !== null && preg_match('/Depth:\s*([0-9.]+)\s*km/i', $text, $matches) ? (float) $matches[1] : null;
    }
}
