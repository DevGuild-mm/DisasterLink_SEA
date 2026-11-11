<?php

namespace App\Services\Disaster\Dashboard;

use App\Models\DisasterEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recent alert-eligible events for one country, for the signed-in user's Welcome page (HLD §8.2, §13.1).
 *
 * Eligible means the event reached the alert state (`pending` or `completed`) on a canonical row of that country.
 * The window is counted from `threshold_met_at`, the moment the event became alert-worthy, not from when it
 * occurred. Newest first, at most 10, cached per country for 60 seconds under `country-alerts:{id}:v1`, which a
 * relevant sync also forgets (see DashboardCache::invalidateAfterSync).
 */
class CountryAlertsQuery
{
    public const WINDOW_DAYS = 7;

    public const LIMIT = 10;

    public const TTL_SECONDS = 60;

    public function __construct(private DashboardCache $cache) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forCountry(int $countryId): array
    {
        try {
            return Cache::remember($this->cache->countryAlertsKey($countryId), self::TTL_SECONDS, fn (): array => $this->build($countryId));
        } catch (Throwable $e) {
            Log::warning('Country alerts cache unavailable, reading MySQL: '.$e->getMessage());

            return $this->build($countryId);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function build(int $countryId): array
    {
        return DisasterEvent::query()
            ->where('country_id', $countryId)
            ->where('country_status', 'resolved')
            ->whereNull('canonical_event_id')
            ->whereIn('alert_status', ['pending', 'completed'])
            ->where('threshold_met_at', '>=', Carbon::now()->subDays(self::WINDOW_DAYS))
            ->orderByDesc('threshold_met_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (DisasterEvent $event): array => $this->present($event))
            ->all();
    }

    /**
     * Only what the page shows. No coordinates, raw payload or other users' data.
     *
     * @return array<string, mixed>
     */
    private function present(DisasterEvent $event): array
    {
        $meta = (array) $event->source_meta;
        $isGdacs = $event->source === 'gdacs';
        $level = $event->alert_level;

        $severity = $isGdacs
            ? ($level !== null ? "{$level} alert" : null)
            : ($event->magnitude !== null ? 'M '.number_format((float) $event->magnitude, 1) : null);

        return [
            'id' => $event->source.'_'.$event->external_id,
            'type' => DashboardQueryService::CATEGORIES[$event->event_type] ?? 'Other disaster',
            'title' => $event->title,
            'location' => $event->location_text,
            'severity' => $severity,
            'severity_text' => $isGdacs ? ($meta['severity_text'] ?? null) : null,
            'source' => strtoupper($event->source),
            'source_url' => $this->safeUrl($event->source_url),
            'alerted_at' => $event->threshold_met_at?->toIso8601String(),
            'occurred_at' => $event->occurred_at?->toIso8601String(),
        ];
    }

    /**
     * A link to the original source, only if it is a plain http(s) address.
     */
    private function safeUrl(?string $url): ?string
    {
        return $url !== null && preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }
}
