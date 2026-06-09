<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('nodes:health')->everyMinute()->withoutOverlapping();
Schedule::command('videos:dispatch')->everyFiveSeconds()->withoutOverlapping();
Schedule::command('videos:reap')->everyMinute()->withoutOverlapping();
Schedule::command('videos:prune')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('videos:gc-tmp')->hourly()->withoutOverlapping();
