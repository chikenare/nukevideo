<?php

use App\Jobs\PruneScratchJob;
use Illuminate\Support\Facades\Schedule;

Schedule::command('videos:dispatch')->everyFiveSeconds()->withoutOverlapping(1);
Schedule::command('videos:reap')->everyMinute()->withoutOverlapping(10);
Schedule::command('videos:prune')->everyThirtyMinutes();

// No-op unless the Bunny CDN driver is active and its Logging API credentials are set.
Schedule::command('bunny:ingest-logs')->everyFiveMinutes()->withoutOverlapping(10);

// job_batches rows otherwise grow forever. Keep a week so PrepareVideoJob's redelivery guard
// (batch looked up by name) comfortably outlives any retry window.
Schedule::command('queue:prune-batches --hours=168 --unfinished=168 --cancelled=168')->daily();

// Reclaim local scratch + the shared chunk store left by failed/deleted/orphaned videos
// (the success path cleans up immediately; this is the backstop). Runs on a worker, via the queue
// EVERY worker drains: `video-processing` is the CPU transcode queue, which an all-GPU fleet has
// no consumer for — the job piled up there unnoticed instead of ever running.
Schedule::job(new PruneScratchJob, 'orchestration')->everyThirtyMinutes();
