<?php

namespace App\Jobs;

use App\Mail\DisasterAlertMail;
use App\Models\DisasterAlertLog;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Sends one alert email (HLD §13.4, v1.3 §27.5).
 *
 * The log row is claimed first with a conditional update, so two workers never send the same alert.
 * `tries = 1`: retries are driven only by the log (`deferred` + `next_attempt_at`).
 * A crash after the provider accepted the mail but before `sent` is recorded leaves the log `sending`; `alerts:dispatch`
 * then fails it as `uncertain` and never resends (at most once).
 */
class SendDisasterAlertEmail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function __construct(public int $logId) {}

    public function uniqueId(): string
    {
        return (string) $this->logId;
    }

    public function handle(): void
    {
        $now = Carbon::now();

        $claimed = DisasterAlertLog::query()
            ->whereKey($this->logId)
            ->where(fn ($query) => $query
                ->where('status', 'pending')
                ->orWhere(fn ($due) => $due->where('status', 'deferred')->where('next_attempt_at', '<=', $now)))
            ->update([
                'status' => 'sending',
                'attempts' => DB::raw('attempts + 1'),
                'last_attempt_at' => $now,
                'updated_at' => $now,
            ]);

        if ($claimed === 0) {
            return;
        }

        $log = DisasterAlertLog::query()->with(['user', 'disasterEvent.country'])->findOrFail($this->logId);
        $user = $log->user;
        $event = $log->disasterEvent;

        if ($user === null || $user->email_verified_at === null || ! $user->alerts_enabled) {
            $this->finish($log, ['status' => 'suppressed', 'suppression_reason' => 'not_eligible']);

            return;
        }

        if (config('alerts.dry_run')) {
            $this->finish($log, ['status' => 'suppressed', 'suppression_reason' => 'dry_run']);

            return;
        }

        if ($resetAt = $this->quotaResetAt($now)) {
            // A quota wait is not an attempt.
            $this->finish($log, [
                'status' => 'deferred',
                'attempts' => max(0, $log->attempts - 1),
                'next_attempt_at' => $resetAt,
                'failure_type' => 'quota',
                'failure_reason' => 'Email quota reached; will retry after the reset.',
            ]);

            return;
        }

        $delayed = $event->occurred_at->lessThan($now->copy()->subHours((int) config('alerts.delayed_after_hours')));

        try {
            $sent = Mail::to($user)->send(new DisasterAlertMail($event, $user, $delayed));
        } catch (TransportExceptionInterface $e) {
            $this->transientFailure($log, $e);

            return;
        } catch (Throwable $e) {
            $this->finish($log, [
                'status' => 'failed',
                'failure_type' => 'permanent',
                'failure_reason' => Str::limit($e->getMessage(), 500, ''),
            ]);

            return;
        }

        $this->finish($log, [
            'status' => 'sent',
            'sent_at' => Carbon::now(),
            'provider_message_id' => $sent?->getMessageId(),
            'next_attempt_at' => null,
            'failure_type' => null,
            'failure_reason' => null,
        ]);
    }

    /**
     * The time the exhausted quota frees up, or null when sending is allowed. Counted from `sent` logs, UTC.
     */
    private function quotaResetAt(Carbon $now): ?Carbon
    {
        $utc = $now->copy()->utc();

        $today = DisasterAlertLog::query()->where('status', 'sent')->where('sent_at', '>=', $utc->copy()->startOfDay())->count();

        if ($today >= (int) config('alerts.daily_limit')) {
            return $utc->copy()->addDay()->startOfDay();
        }

        $month = DisasterAlertLog::query()->where('status', 'sent')->where('sent_at', '>=', $utc->copy()->startOfMonth())->count();

        return $month >= (int) config('alerts.monthly_limit') ? $utc->copy()->addMonthNoOverflow()->startOfMonth() : null;
    }

    private function transientFailure(DisasterAlertLog $log, Throwable $e): void
    {
        $reason = Str::limit($e->getMessage(), 500, '');

        if ($log->attempts >= (int) config('alerts.max_attempts')) {
            $this->finish($log, ['status' => 'failed', 'failure_type' => 'transient', 'failure_reason' => $reason]);

            return;
        }

        $backoff = (array) config('alerts.backoff_minutes');
        $minutes = (int) ($backoff[$log->attempts - 1] ?? end($backoff));

        $this->finish($log, [
            'status' => 'deferred',
            'next_attempt_at' => Carbon::now()->addMinutes($minutes),
            'failure_type' => 'transient',
            'failure_reason' => $reason,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function finish(DisasterAlertLog $log, array $attributes): void
    {
        $log->update($attributes);
    }
}
