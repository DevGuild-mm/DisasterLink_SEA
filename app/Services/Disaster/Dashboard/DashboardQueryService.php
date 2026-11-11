<?php

namespace App\Services\Disaster\Dashboard;

use App\Models\CapitalWeather;
use App\Models\DisasterEvent;
use App\Models\DisasterReport;
use App\Services\Disaster\DisasterDashboardService;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Builds the public dashboard payload from MySQL (HLD v1.4 §28.3). The only reader the pages use.
 * It never calls GDACS, USGS or Open-Meteo.
 */
class DashboardQueryService
{
    /** Events older than this many days are not shown in the live lists. */
    public const WINDOW_DAYS = 7;

    private const MAX_EVENTS = 500;

    public const CATEGORIES = [
        'earthquake' => 'Earthquake',
        'cyclone' => 'Tropical Cyclone',
        'flood' => 'Flood',
        'volcano' => 'Volcano',
        'wildfire' => 'Wildfire',
        'drought' => 'Drought',
    ];

    public function __construct(
        private DashboardCache $cache,
        private SourceHealthService $health,
    ) {}

    /**
     * @param  bool  $reload  rebuild from MySQL now (coalesced to one rebuild per 10 s); never contacts a provider
     * @return array<string, mixed>
     */
    public function dashboard(bool $reload = false): array
    {
        $payload = $reload
            ? $this->cache->reload(fn (): array => $this->build())
            : $this->cache->remember(fn (): array => $this->build());

        return $this->withLiveStatus($payload);
    }

    /**
     * One event by its public id, read from MySQL whatever its age. Null when unknown.
     *
     * @return array<string, mixed>|null
     */
    public function eventById(string $id): ?array
    {
        $id = trim($id);
        [$source, $externalId] = array_pad(explode('_', $id, 2), 2, '');

        if ($source === '' || $externalId === '' || ! in_array(strtolower($source), ['gdacs', 'usgs'], true)) {
            return $this->communityReport($id);
        }

        $event = DisasterEvent::query()
            ->with(['country:id,name,iso2,iso3', 'duplicates:id,canonical_event_id,source,source_url'])
            ->where('source', strtolower($source))
            ->where('external_id', $externalId)
            ->whereNull('canonical_event_id')
            ->first();

        return $event === null ? null : $this->present($event);
    }

    /**
     * A verified community report by id. Pending, under-review and rejected reports are never public.
     *
     * @return array<string, mixed>|null
     */
    private function communityReport(string $id): ?array
    {
        if ($id === '') {
            return null;
        }

        try {
            return DisasterReport::with('user:id,name')
                ->where('id', $id)
                ->where('status', DisasterReport::STATUS_VERIFIED)
                ->first()
                ?->toDisasterEvent();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function build(): array
    {
        $events = DisasterEvent::query()
            ->with(['country:id,name,iso2,iso3', 'duplicates:id,canonical_event_id,source,source_url'])
            ->whereNull('canonical_event_id')
            ->where('country_status', '!=', 'outside_region')
            ->where('occurred_at', '>=', Carbon::now()->subDays(self::WINDOW_DAYS))
            ->orderByDesc('occurred_at')
            ->limit(self::MAX_EVENTS)
            ->get()
            ->map(fn (DisasterEvent $event): array => $this->present($event))
            ->all();

        $merged = array_merge($events, $this->communityReports());

        usort($merged, fn (array $a, array $b): int => strcmp($b['event_time'] ?? '', $a['event_time'] ?? ''));

        return [
            'generated_at' => Carbon::now()->toIso8601String(),
            'summary' => $this->counts($merged),
            'events' => $merged,
            'weather' => $this->cache->weather(fn (): array => $this->weather()),
            'countries' => DisasterDashboardService::ASEAN_COUNTRIES,
            'categories' => [
                ['id' => 'all', 'label' => 'All Categories', 'icon' => 'globe'],
                ['id' => 'earthquake', 'label' => 'Earthquakes', 'icon' => 'activity'],
                ['id' => 'cyclone', 'label' => 'Tropical Cyclones', 'icon' => 'wind'],
                ['id' => 'flood', 'label' => 'Floods', 'icon' => 'waves'],
                ['id' => 'wildfire', 'label' => 'Wildfires', 'icon' => 'flame'],
                ['id' => 'volcano', 'label' => 'Volcanoes', 'icon' => 'triangle-alert'],
                ['id' => 'storm', 'label' => 'Severe Storms', 'icon' => 'cloud-lightning'],
                ['id' => 'other', 'label' => 'Other Incidents', 'icon' => 'alert-circle'],
            ],
        ];
    }

    /**
     * Source health and the summary badges are read live, outside the cached payload.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withLiveStatus(array $payload): array
    {
        $snapshot = $this->health->snapshot();
        $sources = $snapshot['sources'];

        $group = $this->groupStatus([$sources['gdacs']['status'], $sources['usgs']['status']]);

        foreach ($payload['summary'] as $key => $item) {
            if (is_array($item)) {
                $payload['summary'][$key]['status'] = $group;
            }
        }

        $payload['sources'] = $sources;
        $payload['data_as_of'] = $snapshot['data_as_of'];

        return $payload;
    }

    /**
     * @param  array<int, string>  $statuses
     */
    private function groupStatus(array $statuses): string
    {
        if (array_diff($statuses, ['Unavailable']) === []) {
            return 'Unavailable';
        }

        return in_array('Live', $statuses, true) ? 'Active' : 'Stale';
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DisasterEvent $event): array
    {
        $category = self::CATEGORIES[$event->event_type] ?? 'Other Disaster';
        $sourceName = strtoupper($event->source);
        $isGdacs = $event->source === 'gdacs';
        $meta = (array) $event->source_meta;
        $level = $event->alert_level;
        $severityText = $isGdacs
            ? ($meta['severity_text'] ?? ($level !== null ? "{$level} Alert" : null))
            : ($event->magnitude !== null ? 'M '.number_format((float) $event->magnitude, 1) : null);

        $severity = match (true) {
            $level !== null && $severityText !== null && $isGdacs => "{$level} Alert ({$severityText})",
            $level !== null => "{$level} Alert",
            default => $severityText,
        };

        $latitude = $event->latitude !== null ? (float) $event->latitude : null;
        $longitude = $event->longitude !== null ? (float) $event->longitude : null;

        $sourceUrls = collect([['source' => $sourceName, 'url' => $event->source_url]])
            ->merge($event->duplicates->map(fn (DisasterEvent $d): array => ['source' => strtoupper($d->source), 'url' => $d->source_url]))
            ->filter(fn (array $entry): bool => ! empty($entry['url']))
            ->values()
            ->all();

        $countryKey = $this->countryKey($event);

        return [
            'id' => $event->source.'_'.$event->external_id,
            'source' => $sourceName,
            'sources' => collect([$sourceName])->merge($event->duplicates->map(fn (DisasterEvent $d): string => strtoupper($d->source)))->unique()->values()->all(),
            'source_id' => $event->external_id,
            'disaster_type' => $category,
            'category' => $category,
            'raw_type' => $isGdacs ? strtoupper(explode(':', $event->external_id)[0]) : 'earthquake',
            'title' => $event->title,
            'description' => $event->description,
            'country' => $countryKey,
            'location' => $event->location_text,
            'iso3' => $event->country?->iso3 ?? ($meta['iso3'] ?? null),
            'latitude' => $latitude,
            'longitude' => $longitude,
            'coordinates' => ($latitude !== null && $longitude !== null) ? [$latitude, $longitude] : null,
            'magnitude' => $event->magnitude !== null ? (float) $event->magnitude : null,
            'depth_km' => $event->depth_km !== null ? (float) $event->depth_km : null,
            'severity' => $severity,
            'severity_level' => $level,
            'severity_score' => $event->severity_value !== null ? (float) $event->severity_value : null,
            'severity_text' => $severityText,
            'event_date' => $event->occurred_at->toIso8601String(),
            'event_time' => $event->occurred_at->toIso8601String(),
            'last_updated_time' => $event->source_updated_at?->toIso8601String(),
            'source_url' => $event->source_url ?? '',
            'source_urls' => $sourceUrls,
        ];
    }

    /**
     * The ASEAN key used by the pages ("Laos", "Timor-Leste"...), or a regional label for an unresolved event.
     */
    private function countryKey(DisasterEvent $event): string
    {
        $iso3 = $event->country?->iso3;

        foreach (DisasterDashboardService::ASEAN_COUNTRIES as $key => $country) {
            if ($iso3 !== null && $country['iso3'] === $iso3) {
                return $key;
            }
        }

        return $event->country?->name ?? 'Regional / Unattributed';
    }

    /**
     * Verified community reports only, never pending or rejected ones.
     *
     * @return array<int, array<string, mixed>>
     */
    private function communityReports(): array
    {
        try {
            return DisasterReport::with('user:id,name')
                ->where('status', DisasterReport::STATUS_VERIFIED)
                ->get()
                ->map(fn (DisasterReport $report) => $report->toDisasterEvent())
                ->toArray();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function weather(): array
    {
        return CapitalWeather::query()
            ->with('country:id,name,iso2,iso3,capital_name,capital_lat,capital_lng,sort_order')
            ->get()
            ->sortBy(fn (CapitalWeather $row): int => (int) $row->country?->sort_order)
            ->map(fn (CapitalWeather $row): array => [
                'country' => $this->weatherCountryKey($row),
                'city' => $row->country->capital_name,
                'iso2' => $row->country->iso2,
                'iso3' => $row->country->iso3,
                'latitude' => (float) $row->country->capital_lat,
                'longitude' => (float) $row->country->capital_lng,
                'temperature_c' => $row->temperature_c !== null ? (float) $row->temperature_c : null,
                'apparent_temperature_c' => null,
                'humidity_pct' => $row->humidity_pct,
                'precipitation_mm' => (float) ($row->precipitation_mm ?? 0),
                'wind_speed_kmh' => $row->wind_speed_kmh !== null ? (float) $row->wind_speed_kmh : null,
                'wind_direction_deg' => null,
                'weather_code' => (int) $row->weather_code,
                'condition' => $row->condition ?? 'Unknown',
                'icon' => $row->icon ?? 'cloud',
                'is_day' => true,
                'forecast_max_c' => $row->forecast_max_c !== null ? (float) $row->forecast_max_c : null,
                'forecast_min_c' => $row->forecast_min_c !== null ? (float) $row->forecast_min_c : null,
                'forecast_precipitation_sum_mm' => null,
                'observation_time' => $row->observed_at?->toIso8601String() ?? '',
                'data_type' => 'Numerical weather model (Open-Meteo)',
            ])
            ->values()
            ->all();
    }

    private function weatherCountryKey(CapitalWeather $row): string
    {
        foreach (DisasterDashboardService::ASEAN_COUNTRIES as $key => $country) {
            if ($country['iso3'] === $row->country->iso3) {
                return $key;
            }
        }

        return $row->country->name;
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @return array<string, mixed>
     */
    private function counts(array $events): array
    {
        $active = 0;
        $byCategory = ['earthquake' => 0, 'cyclones' => 0, 'flood' => 0, 'wildfire' => 0, 'volcano' => 0];

        foreach ($events as $event) {
            if (in_array($event['severity_level'] ?? null, ['Orange', 'Red'], true)) {
                $active++;
            }

            match (strtolower((string) ($event['category'] ?? $event['disaster_type'] ?? ''))) {
                'earthquake' => $byCategory['earthquake']++,
                'tropical cyclone', 'severe storm' => $byCategory['cyclones']++,
                'flood' => $byCategory['flood']++,
                'wildfire' => $byCategory['wildfire']++,
                'volcano' => $byCategory['volcano']++,
                default => null,
            };
        }

        return [
            'total_active_alerts' => ['count' => $active, 'status' => 'Unavailable', 'description' => 'Orange & Red Alert incidents requiring urgent regional attention'],
            'earthquakes' => ['count' => $byCategory['earthquake'], 'status' => 'Unavailable', 'description' => 'Seismic events registered in Southeast Asia'],
            'tropical_cyclones' => ['count' => $byCategory['cyclones'], 'status' => 'Unavailable', 'description' => 'Tracked tropical depressions, storms, and typhoons'],
            'floods' => ['count' => $byCategory['flood'], 'status' => 'Unavailable', 'description' => 'Riverine & flash flood events reported in the region'],
            'wildfires' => ['count' => $byCategory['wildfire'], 'status' => 'Unavailable', 'description' => 'Wildfire events reported in the region'],
            'volcanoes' => ['count' => $byCategory['volcano'], 'status' => 'Unavailable', 'description' => 'Volcanic activity & ash cloud warnings'],
            'total_events' => count($events),
        ];
    }
}
