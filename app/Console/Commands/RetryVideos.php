<?php

namespace App\Console\Commands;

use App\Enums\VideoStatus;
use App\Models\Video;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Puts a failed video back in line for the encoder. The pipeline has no other way in: a video is
 * dispatched only out of PENDING, and everything a failed run left behind — its batch rows, the
 * error on its streams, the progress of the attempt that died — has to be cleared first or the
 * retry either hangs or lies about what it is doing.
 */
class RetryVideos extends Command
{
    protected $signature = 'videos:retry
        {video* : Video ids or ULIDs}
        {--reprobe : Drop the derived streams and outputs so the source is probed from scratch}
        {--dry-run : Report what would happen, change nothing}';

    protected $description = 'Requeue failed videos, clearing the state their failed run left behind';

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

        if ($video->status !== VideoStatus::FAILED->value) {
            $this->error("{$label} is {$video->status}, not failed — nothing to retry.");

            return false;
        }

        // A retry that cannot read the source is a 20-minute walk to the same failure. The mirror
        // is the cheap path; the upload it was mirrored from is the fallback PrepareVideoJob takes.
        if (! $this->sourceIsReachable($video)) {
            $this->error("{$label}: the source is gone from both the internal mirror and S3 — it cannot be retried.");

            return false;
        }

        // An unfinished batch means jobs are still on their way to this video; let them land
        // rather than fan out a second set alongside them.
        $live = DB::table('job_batches')
            ->where('name', 'like', "encode video {$video->id} %")
            ->whereNull('finished_at')
            ->count();

        if ($live > 0) {
            $this->error("{$label}: {$live} encode batch(es) still unfinished — its jobs are still coming. Wait for them.");

            return false;
        }

        if ($this->option('dry-run')) {
            $this->line("{$label}: would requeue".($this->option('reprobe') ? ' and re-probe the source.' : '.'));

            return true;
        }

        // The finished batches of the run that failed. PrepareVideoJob treats any of them as
        // "fan-out already happened" and would skip planning entirely, leaving the video queued
        // behind a job that does nothing; they are spent bookkeeping either way
        // ({@see queue:prune-batches} drops them a week later).
        DB::table('job_batches')->where('name', 'like', "encode video {$video->id} %")->delete();

        // Nothing else ever clears these: the panel would show the failed run's error and its
        // progress bar frozen wherever it died, on top of a video that is encoding again.
        $video->outputs()->get()->each->clearChunkProgress();
        $video->streams()->whereNotNull('error_log')->update(['error_log' => null]);

        if ($this->option('reprobe')) {
            // Outputs too: CreateVideoStreamsService creates a fresh one per template output, so
            // keeping the old ones would leave a second, streamless set attached to the video.
            // The output_stream pivot cascades on both sides.
            $video->outputs()->get()->each->delete();
            $video->streams()->where('type', '!=', 'original')->delete();
        } else {
            $video->outputs()->update(['status' => VideoStatus::PENDING->value]);
        }

        $video->update([
            'status' => VideoStatus::PENDING->value,
            'last_heartbeat_at' => null,
        ]);

        $this->info("{$label}: queued for another run.");

        return true;
    }

    private function sourceIsReachable(Video $video): bool
    {
        $original = $video->streams()->where('type', 'original')->value('path');

        if ($original && Storage::disk('s3')->exists($original)) {
            return true;
        }

        $extension = pathinfo((string) $original, PATHINFO_EXTENSION) ?: 'mp4';

        return Storage::disk('chunks')->exists($video->sourceMirrorPath($extension));
    }
}
