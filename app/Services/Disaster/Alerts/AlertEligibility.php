<?php

namespace App\Services\Disaster\Alerts;

use App\Models\DisasterEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Decides which events become alert-eligible (HLD §13.1 with the v1.3 §27.3 recency rule).
 *
 * An event is eligible when, on a canonical (not linked-away), non-synthetic row with a resolved country,
 * the event or a linked earthquake of its group meets its own source's threshold, and one of:
 * - new significant event: it occurred within the recency window, or
 * - observed escalation: it was stored before it met the threshold, and it met it within the recency window.
 *
 * `threshold_met_at` is the first sync that saw the group at or above threshold. It is set once and never cleared.
 * An event first seen already above threshold and older than the window never alerts (first launch, downtime).
 */
class AlertEligibility
{
    /**
     * Evaluate canonical events and mark the eligible ones `pending`.
     *
     * @param  array<int, int>  $canonicalIds
     * @return array<int, int> ids that became pending
     */
    public function evaluate(array $canonicalIds, ?CarbonInterface $now = null): array
    {
        $now = Carbon::instance($now ?? Carbon::now())->startOfSecond();
        $pending = [];

        $events = DisasterEvent::query()
            ->whereIn('id', $canonicalIds)
            ->whereNull('canonical_event_id')
            ->where('alert_status', 'not_eligible')
            ->get();

        foreach ($events as $event) {
            if (! in_array($event->source, ['gdacs', 'usgs'], true) || ! empty($event->source_meta['synthetic_id'])) {
                continue;
            }

            if (! $this->groupMeetsThreshold($event)) {
                continue;
            }

            if ($event->threshold_met_at === null) {
                $event->threshold_met_at = $now;
                $event->save();
            }

            if ($event->country_status !== 'resolved' || $event->country_id === null || ! $this->triggered($event, $now)) {
                continue;
            }

            $event->alert_status = 'pending';
            $event->save();

            $pending[] = $event->id;
        }

        return $pending;
    }

    public function meetsThreshold(DisasterEvent $event): bool
    {
        if ($event->source === 'gdacs') {
            return in_array($event->alert_level, (array) config('alerts.gdacs_levels'), true);
        }

        if ($event->source === 'usgs') {
            return $event->magnitude !== null && (float) $event->magnitude >= (float) config('alerts.usgs_min_magnitude');
        }

        return false;
    }

    private function groupMeetsThreshold(DisasterEvent $event): bool
    {
        if ($this->meetsThreshold($event)) {
            return true;
        }

        return $event->duplicates()->get()->contains(fn (DisasterEvent $member): bool => $this->meetsThreshold($member));
    }

    private function triggered(DisasterEvent $event, Carbon $now): bool
    {
        $window = $now->copy()->subHours((int) config('alerts.recency_hours'));

        $newSignificant = $event->occurred_at->greaterThanOrEqualTo($window);
        $escalation = $event->first_seen_at->lessThan($event->threshold_met_at)
            && $event->threshold_met_at->greaterThanOrEqualTo($window);

        return $newSignificant || $escalation;
    }
}
