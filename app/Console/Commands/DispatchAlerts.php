<?php

namespace App\Console\Commands;

use App\Jobs\ProcessEventAlerts;
use App\Jobs\SendDisasterAlertEmail;
use App\Models\DisasterAlertLog;
use App\Models\DisasterEvent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('alerts:dispatch')]
#[Description('Queue alert work that is due or was missed: pending events, pending and deferred emails, stuck sends')]
class DispatchAlerts extends Command
{
    /**
     * Execute the console command. Safe to run repeatedly: the jobs are unique and the logs are claimed atomically.
     */
    public function handle(): int
    {
        $now = Carbon::now();
        $pendingBefore = $now->copy()->subMinutes((int) config('alerts.recover_pending_after'));

        $events = DisasterEvent::query()
            ->where('alert_status', 'pending')
            ->where('threshold_met_at', '<=', $pendingBefore)
            ->pluck('id');
        $events->each(fn (int $id) => ProcessEventAlerts::dispatch($id));

        $logs = DisasterAlertLog::query()
            ->join('disaster_events', 'disaster_events.id', '=', 'disaster_alert_logs.disaster_event_id')
            ->where(fn ($query) => $query
                ->where(fn ($pending) => $pending->where('disaster_alert_logs.status', 'pending')->where('disaster_alert_logs.created_at', '<=', $pendingBefore))
                ->orWhere(fn ($due) => $due->where('disaster_alert_logs.status', 'deferred')->where('disaster_alert_logs.next_attempt_at', '<=', $now)))
            ->orderBy('disaster_events.occurred_at')
            ->orderBy('disaster_alert_logs.id')
            ->pluck('disaster_alert_logs.id');
        $logs->each(fn (int $id) => SendDisasterAlertEmail::dispatch($id));

        $stuck = DisasterAlertLog::query()
            ->where('status', 'sending')
            ->where('last_attempt_at', '<=', $now->copy()->subMinutes((int) config('alerts.sending_stuck_after')))
            ->update([
                'status' => 'failed',
                'failure_type' => 'uncertain',
                'failure_reason' => 'The send did not finish; it may or may not have been delivered. Not resent.',
                'updated_at' => $now,
            ]);

        $this->components->info("Queued {$events->count()} event(s) and {$logs->count()} email(s); marked {$stuck} stuck send(s) as failed.");

        return self::SUCCESS;
    }
}
