<?php

use App\Jobs\PruneScratchJob;
use Illuminate\Support\Facades\Schedule;

Schedule::command('videos:dispatch')->everyFiveSeconds();
Schedule::command('videos:reap')->everyMinute()->withoutOverlapping(10);
Schedule::command('videos:prune')->everyThirtyMinutes();

// Reclaim local scratch + the shared chunk store left by failed/deleted/orphaned videos
// (the success path cleans up immediately; this is the backstop). Runs on a worker.
Schedule::job(new PruneScratchJob, 'video-processing')->everyThirtyMinutes();
