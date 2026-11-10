<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Disaster data sync (HLD v1.3 §10.3, v1.4 §28.9). Times are staggered so two sources never fetch in the same minute.
// The 10-minute overlap expiry outlives the 300 s service lock; the service lock is the real guard.
Schedule::command('disaster:sync gdacs')->cron('2,17,32,47 * * * *')->withoutOverlapping(10);
Schedule::command('disaster:sync usgs')->cron('4-59/5 * * * *')->withoutOverlapping(10);

// Alert recovery (HLD v1.3 §27.5): queue missed alert work and fail stuck sends.
Schedule::command('alerts:dispatch')->everyFiveMinutes()->withoutOverlapping(10);

// Capital weather (HLD §14): one batched Open-Meteo request every 30 minutes, staggered from the event syncs.
Schedule::command('weather:refresh-capitals')->cron('12,42 * * * *')->withoutOverlapping(10);
