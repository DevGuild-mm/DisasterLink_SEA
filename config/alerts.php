<?php

/**
 * Country-level alert emails (HLD v1.2 §13, v1.3 §27.3 and §27.5).
 */
return [

    /*
     * While true, alert logs become `suppressed` (reason dry_run) and nothing is sent. Set ALERTS_DRY_RUN=false
     * to send through the configured mailer (MAIL_MAILER=log writes to storage/logs, or use Mailpit).
     */
    'dry_run' => (bool) env('ALERTS_DRY_RUN', true),

    /* Severity thresholds (HLD §13.1). GDACS: Orange or Red. USGS: magnitude at or above this value. */
    'gdacs_levels' => ['Orange', 'Red'],
    'usgs_min_magnitude' => 5.0,

    /* An event alerts only if it happened, or crossed its threshold, within this many hours (HLD §27.3). */
    'recency_hours' => 24,

    /* Cross-source earthquake duplicates (HLD §11.5). */
    'link' => [
        'max_minutes' => 5,
        'max_km' => 50,
        'max_magnitude_difference' => 0.5,
    ],

    /* Delivery quota counted from alert logs with status `sent` (UTC day and month). Resend free plan margin. */
    'daily_limit' => (int) env('ALERT_EMAIL_DAILY_LIMIT', 95),
    'monthly_limit' => (int) env('ALERT_EMAIL_MONTHLY_LIMIT', 2950),

    /* Retries of a transient mail failure: minutes to wait after attempt 1, 2, 3, ...; then `failed`. */
    'max_attempts' => 5,
    'backoff_minutes' => [1, 5, 15, 60],

    /* A deferred alert whose event is older than this when it is finally sent carries a "delayed" note. */
    'delayed_after_hours' => 48,

    /* `alerts:dispatch` recovery windows (minutes). */
    'recover_pending_after' => 5,
    'sending_stuck_after' => 15,

    /* Batch size when selecting recipients. */
    'recipient_chunk' => 500,
];
