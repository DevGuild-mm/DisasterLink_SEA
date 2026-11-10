<?php

namespace App\Jobs;

use App\Models\DisasterAlertLog;
use App\Models\DisasterEvent;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * Creates one pending alert log per eligible user in the event's country, then queues the emails (HLD §13.4).
 *
 * Safe to run again: the unique (user, event) key stops duplicate logs and the event is completed once.
 */
class ProcessEventAlerts implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function __construct(public int $eventId) {}

    public function uniqueId(): string
    {
        return (string) $this->eventId;
    }

    public function handle(): void
    {
        $event = DisasterEvent::query()->find($this->eventId);

        if ($event === null || $event->alert_status !== 'pending' || $event->country_id === null || $event->canonical_event_id !== null) {
            return;
        }

        User::query()
            ->where('country_id', $event->country_id)
            ->whereNotNull('email_verified_at')
            ->where('alerts_enabled', true)
            ->chunkById((int) config('alerts.recipient_chunk'), function ($users) use ($event): void {
                $now = Carbon::now();

                DisasterAlertLog::query()->insertOrIgnore($users->map(fn (User $user): array => [
                    'user_id' => $user->id,
                    'disaster_event_id' => $event->id,
                    'status' => 'pending',
                    'attempts' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });

        DisasterAlertLog::query()
            ->where('disaster_event_id', $event->id)
            ->where('status', 'pending')
            ->pluck('id')
            ->each(fn (int $logId) => SendDisasterAlertEmail::dispatch($logId));

        DisasterEvent::query()
            ->whereKey($event->id)
            ->where('alert_status', 'pending')
            ->update(['alert_status' => 'completed', 'alerts_completed_at' => Carbon::now()]);
    }
}
