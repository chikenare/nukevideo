<?php

namespace App\Console\Commands;

use App\Models\Video;
use App\Services\VideoService;
use Illuminate\Console\Command;

/**
 * Puts a failed video back in line for the encoder. The work itself lives in
 * {@see VideoService::retry()}, which the panel's retry button calls too — the CLI keeps what is
 * genuinely its own: taking several videos at once, resolving them by id or ULID, and reporting
 * each refusal instead of stopping at the first.
 */
class RetryVideos extends Command
{
    protected $signature = 'videos:retry
        {video* : Video ids or ULIDs}
        {--reprobe : Drop the derived streams and outputs so the source is probed from scratch}
        {--dry-run : Report what would happen, change nothing}';

    protected $description = 'Requeue failed videos, clearing the state their failed run left behind';

    public function __construct(private VideoService $videos)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $failed = 0;

        foreach ($this->argument('video') as $key) {
            $video = Video::where('id', is_numeric($key) ? $key : 0)
                ->orWhere('ulid', $key)
                ->first();

            if (! $video) {
                $this->error("No video matches {$key}.");
                $failed++;

                continue;
            }

            if (! $this->retry($video)) {
                $failed++;
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function retry(Video $video): bool
    {
        $label = "Video {$video->id} ({$video->ulid})";

        // Asked for up front rather than caught out of retry(): --dry-run has to report the same
        // refusals without touching anything, and one unretryable video must not end the run.
        if ($blocker = $this->videos->retryBlocker($video)) {
            $this->error("{$label}: {$blocker}");

            return false;
        }

        if ($this->option('dry-run')) {
            $this->line("{$label}: would requeue".($this->option('reprobe') ? ' and re-probe the source.' : '.'));

            return true;
        }

        $this->videos->retry($video, (bool) $this->option('reprobe'));

        $this->info("{$label}: queued for another run.");

        return true;
    }
}
