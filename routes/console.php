<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Disaster data sync (HLD v1.3 §10.3). Times are staggered so two sources never fetch in the same minute.
Schedule::command('disaster:sync gdacs')->cron('2,17,32,47 * * * *')->withoutOverlapping();
Schedule::command('disaster:sync usgs')->cron('4-59/5 * * * *')->withoutOverlapping();
