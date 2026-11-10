<?php

namespace App\Services\Disaster\Dashboard;

use App\Models\CapitalWeather;
use App\Models\DataSource;
use App\Models\DisasterEvent;
use Illuminate\Support\Carbon;

/**
 * Status of the three sources, always read live from `data_sources` and never cached (HLD v1.4 §28.5),
 * so a no-change sync keeps the displayed "last successful sync" accurate without clearing the dashboard cache.
 */
class SourceHealthService
{
    private const DOCS = [
        'gdacs' => ['GDACS', 'Global Disaster Alert and Coordination System (UN / EC JRC)', 'Every 15 minutes (scheduled sync)', 'https://www.gdacs.org/gdacsapi/swagger/index.html'],
        'usgs' => ['USGS', 'United States Geological Survey (Earthquake Hazards Program)', 'Every 5 minutes (scheduled sync)', 'https://earthquake.usgs.gov/earthquakes/feed/v1.0/summary/all_day.geojson'],
        'open_meteo' => ['Open-Meteo', 'Open-Meteo Weather Forecast API', 'Every 30 minutes (scheduled sync)', 'https://open-meteo.com/en/docs'],
    ];

    /**
     * Keyed gdacs, usgs and weather (the keys the front end already uses).
     *
     * @return array{sources: array<string, array<string, mixed>>, data_as_of: string|null}
     */
    public function snapshot(): array
    {
        $rows = DataSource::query()->get()->keyBy('key');

        $sources = [
            'gdacs' => $this->describe('gdacs', $rows->get('gdacs'), DisasterEvent::query()->where('source', 'gdacs')->max('occurred_at')),
            'usgs' => $this->describe('usgs', $rows->get('usgs'), DisasterEvent::query()->where('source', 'usgs')->max('occurred_at')),
            'weather' => $this->describe('open_meteo', $rows->get('open_meteo'), CapitalWeather::query()->max('observed_at')),
        ];

        $sources['weather']['cities_count'] = CapitalWeather::query()->count();

        $successTimes = collect(['gdacs', 'usgs'])
            ->map(fn (string $key) => $rows->get($key)?->last_success_at)
            ->filter();

        return [
            'sources' => $sources,
            'data_as_of' => $successTimes->isEmpty() ? null : $successTimes->min()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(string $key, ?DataSource $row, mixed $latest): array
    {
        [$label, $name, $frequency, $documentation] = self::DOCS[$key];

        $interval = max(1, (int) ($row?->interval_minutes ?? 15));
        $lastSuccess = $row?->last_success_at;
        $isFresh = $lastSuccess !== null && $lastSuccess->greaterThanOrEqualTo(Carbon::now()->subMinutes($interval * 2));
        $isStale = $lastSuccess !== null && ! $isFresh;
        $failed = $row?->last_status === 'failed';

        $status = match (true) {
            $lastSuccess === null => 'Unavailable',
            $isFresh => 'Live',
            default => 'Stale',
        };

        $connection = match (true) {
            $row === null || $row->last_attempt_at === null => 'Never synced',
            $failed => 'Last sync failed',
            default => 'Last sync succeeded',
        };

        return [
            'source' => $label,
            'name' => $name,
            'status' => $status,
            'freshness_status' => $status === 'Live' ? 'Fresh' : ($status === 'Stale' ? 'Stale' : 'Unavailable'),
            'connection_status' => $connection,
            'last_successful_fetch' => $lastSuccess?->toIso8601String(),
            'latest_event_time' => $latest === null ? null : Carbon::parse($latest)->toIso8601String(),
            'total_features_fetched' => $row?->last_fetched_count,
            'sea_events_count' => $row?->last_processed_count,
            'cache_ttl_seconds' => DashboardCache::PAYLOAD_TTL,
            'provider_update_frequency' => $frequency,
            'frontend_poll_interval_seconds' => 60,
            'is_fresh' => $isFresh,
            'is_stale' => $isStale,
            'error' => $failed ? $row?->last_error : null,
            'documentation_url' => $documentation,
        ];
    }
}
