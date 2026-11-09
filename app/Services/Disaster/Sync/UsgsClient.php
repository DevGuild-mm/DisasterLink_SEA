<?php

namespace App\Services\Disaster\Sync;

use Carbon\CarbonImmutable;

/**
 * Fetches the USGS "all day" earthquake feed and normalises events in the ASEAN region box.
 *
 * USGS gives no country code. The free-text `place` is stored for display only and is never
 * used to decide a country (HLD §10.3, §12.1).
 */
class UsgsClient extends SourceClient
{
    public function key(): string
    {
        return 'usgs';
    }

    public function fetch(): FetchResult
    {
        $response = $this->send();
        $data = $response->json();

        if (! is_array($data) || ! isset($data['features']) || ! is_array($data['features'])) {
            throw new SourceFetchException('USGS returned an unexpected response shape.', $response->status());
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

        if (strtolower((string) ($props['type'] ?? 'earthquake')) !== 'earthquake') {
            return null;
        }

        $point = $this->point($feature);

        if ($point === null || ! $this->inRegion($point[0], $point[1])) {
            return null;
        }

        if (! isset($props['time']) || ! is_numeric($props['time'])) {
            return null;
        }

        $occurredAt = CarbonImmutable::createFromTimestampMsUTC((int) $props['time']);

        $id = trim((string) ($feature['id'] ?? ''));
        $synthetic = $id === '';
        $externalId = $synthetic ? $this->syntheticId('earthquake', $point[0], $point[1], $occurredAt) : $id;

        $magnitude = isset($props['mag']) && is_numeric($props['mag']) ? round((float) $props['mag'], 1) : null;
        $depth = $feature['geometry']['coordinates'][2] ?? null;
        $place = $this->cleanText($props['place'] ?? null, 255);

        $title = $this->cleanText($props['title'] ?? null, 255)
            ?? ($magnitude !== null ? "M {$magnitude} Earthquake" : 'Earthquake').($place !== null ? " - {$place}" : '');

        return new NormalizedEvent(
            source: 'usgs',
            externalId: $externalId,
            episodeId: null,
            eventType: 'earthquake',
            title: $title,
            description: null,
            locationText: $place,
            alertLevel: $this->derivedAlertLevel($magnitude),
            alertLevelDerived: $magnitude !== null,
            magnitude: $magnitude,
            severityValue: $magnitude,
            severityUnit: $magnitude !== null ? 'M' : null,
            depthKm: is_numeric($depth) ? round((float) $depth, 2) : null,
            latitude: $point[0],
            longitude: $point[1],
            occurredAt: $occurredAt,
            endedAt: null,
            sourceUpdatedAt: isset($props['updated']) && is_numeric($props['updated'])
                ? CarbonImmutable::createFromTimestampMsUTC((int) $props['updated'])
                : null,
            sourceUrl: $this->safeUrl($props['url'] ?? null),
            sourceMeta: array_filter([
                'status' => $this->cleanText($props['status'] ?? null, 30),
                'mag_type' => $this->cleanText($props['magType'] ?? null, 20),
                'tsunami' => ! empty($props['tsunami']) ? true : null,
                'pager_alert' => $this->cleanText($props['alert'] ?? null, 20),
                'synthetic_id' => $synthetic ?: null,
            ], fn (mixed $value): bool => $value !== null),
            rawPayload: $feature,
        );
    }

    /**
     * Display only (HLD §10.4): M 6.5+ Red, M 5.0+ Orange, otherwise Green.
     */
    private function derivedAlertLevel(?float $magnitude): ?string
    {
        return match (true) {
            $magnitude === null => null,
            $magnitude >= 6.5 => 'Red',
            $magnitude >= 5.0 => 'Orange',
            default => 'Green',
        };
    }
}
