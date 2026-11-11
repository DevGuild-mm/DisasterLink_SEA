<?php

namespace App\Services\Disaster\Sync;

use App\Models\DisasterEvent;

/**
 * Links the same earthquake reported by both USGS and GDACS (HLD §11.5). Only earthquakes are linked.
 *
 * A pair is linked only if the times differ by at most `link.max_minutes`, the epicentres by at most
 * `link.max_km`, and the magnitudes by at most `link.max_magnitude_difference`. The row seen first stays
 * canonical; the other points at it through `canonical_event_id`. A group has at most two rows, never a chain.
 * Alerts run for the canonical row only.
 *
 * A canonical row with no country takes its linked row's source-provided country (`linked_source`, HLD §27.4).
 */
class EarthquakeLinker
{
    /**
     * @param  array<int, int>  $eventIds  events just stored by a sync
     * @return array{canonical: array<int, int>, changes: int} canonical ids to evaluate, and how many rows changed
     */
    public function link(array $eventIds): array
    {
        $changes = 0;
        $canonical = [];

        $events = DisasterEvent::query()->whereIn('id', $eventIds)->get();

        foreach ($events as $event) {
            $event->refresh();

            if ($event->event_type === 'earthquake' && $event->canonical_event_id === null && ! $event->duplicates()->exists()) {
                $match = $this->findMatch($event);

                if ($match !== null) {
                    [$parent, $child] = $this->order($event, $match);
                    $child->canonical_event_id = $parent->id;
                    $child->save();
                    $changes++;
                }
            }

            $root = $event->canonical_event_id !== null ? $event->canonicalEvent : $event->fresh();

            if ($root !== null) {
                $changes += $this->shareCountry($root) ? 1 : 0;
                $canonical[$root->id] = $root->id;
            }
        }

        return ['canonical' => array_values($canonical), 'changes' => $changes];
    }

    private function findMatch(DisasterEvent $event): ?DisasterEvent
    {
        if ($event->latitude === null || $event->longitude === null || $event->magnitude === null) {
            return null;
        }

        $minutes = (int) config('alerts.link.max_minutes');
        $maxKm = (float) config('alerts.link.max_km');
        $maxMagnitude = (float) config('alerts.link.max_magnitude_difference');

        $best = null;
        $bestKm = INF;

        $candidates = DisasterEvent::query()
            ->where('event_type', 'earthquake')
            ->where('source', '!=', $event->source)
            ->whereNull('canonical_event_id')
            ->whereNotNull('magnitude')
            ->whereBetween('occurred_at', [$event->occurred_at->copy()->subMinutes($minutes), $event->occurred_at->copy()->addMinutes($minutes)])
            ->get();

        foreach ($candidates as $candidate) {
            if ($candidate->latitude === null
                || abs((float) $candidate->magnitude - (float) $event->magnitude) > $maxMagnitude
                || $candidate->duplicates()->exists()) {
                continue;
            }

            $km = Geometry::haversineKm((float) $event->latitude, (float) $event->longitude, (float) $candidate->latitude, (float) $candidate->longitude);

            if ($km <= $maxKm && $km < $bestKm) {
                $best = $candidate;
                $bestKm = $km;
            }
        }

        return $best;
    }

    /**
     * The row seen first is the parent (canonical); ties go to the lower id.
     *
     * @return array{0: DisasterEvent, 1: DisasterEvent}
     */
    private function order(DisasterEvent $a, DisasterEvent $b): array
    {
        $aFirst = $a->first_seen_at->lessThan($b->first_seen_at)
            || ($a->first_seen_at->equalTo($b->first_seen_at) && $a->id < $b->id);

        return $aFirst ? [$a, $b] : [$b, $a];
    }

    /**
     * An unresolved canonical row takes the country its linked row got from the source itself.
     */
    private function shareCountry(DisasterEvent $canonical): bool
    {
        if ($canonical->country_status === 'resolved') {
            return false;
        }

        $source = $canonical->duplicates()
            ->where('country_status', 'resolved')
            ->where('country_method', 'source_code')
            ->first();

        if ($source === null) {
            return false;
        }

        $canonical->forceFill([
            'country_id' => $source->country_id,
            'country_status' => 'resolved',
            'country_method' => 'linked_source',
            'country_note' => null,
        ])->save();

        return true;
    }
}
