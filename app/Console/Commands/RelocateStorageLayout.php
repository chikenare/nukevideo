<?php

namespace App\Console\Commands;

use App\Models\Stream;
use App\Models\Video;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Copies a video's objects from the flat layout into the zoned one
 * ({@see Video} — `play/`, `download/`, `assets/`, `original/`).
 *
 * Run this BEFORE deploying the code that reads the zones. The old build keeps serving from the old
 * keys while this runs, the new build then finds every key already in place, and a rollback is a
 * redeploy rather than a migration in reverse. That ordering is the whole reason nothing here
 * deletes: the old objects stay until a separate sweep removes them, days or weeks later, once the
 * new layout has proven itself.
 *
 * Anything packaged while this is running lands in the old layout and needs a second pass — filter
 * with `--video=` or simply re-run, since copying an object that is already at its destination is
 * harmless.
 */
class RelocateStorageLayout extends Command
{
    protected $signature = 'videos:relocate-storage
        {video?* : Video ids or ULIDs; omit to sweep every video}
        {--dry-run : Print the plan and copy nothing}
        {--limit= : Stop after this many videos}';

    protected $description = 'Copy each video\'s objects into the zoned S3 layout, without deleting the originals';

    /**
     * The zone names as literals, deliberately NOT the `Video::*_DIR` constants.
     *
     * This command runs against the build that is live BEFORE the zoned layout ships — that is the
     * whole point of copying first — so anything it borrows from the new model simply is not there.
     * The constants are the half that fails loudly; `Stream::archivePath()` is the half that does
     * not, since the old one returns the old location and the rewrite below would quietly no-op.
     */
    private const PLAY = 'play';

    private const DOWNLOAD = 'download';

    private const ASSETS = 'assets';

    private const ORIGINAL = 'original';

    /** Zone names, so an object already relocated is never planned again. */
    private const ZONES = [self::PLAY, self::DOWNLOAD, self::ASSETS, self::ORIGINAL];

    public function handle(): int
    {
        $videos = $this->targets();

        if ($videos->isEmpty()) {
            $this->info('Nothing to relocate.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $totalCopies = 0;
        $failed = 0;

        foreach ($videos as $video) {
            $label = "Video {$video->id} ({$video->ulid})";

            // A video still being written is a moving target: the packager is syncing into the very
            // prefix this would walk, so its listing would be a snapshot of a half-finished tree.
            if (in_array($video->status, Video::ACTIVE_STATUSES, true)) {
                $this->warn("{$label}: still processing, skipping.");

                continue;
            }

            $plan = $this->plan($video);

            if ($plan === []) {
                $this->line("{$label}: already in the zoned layout.");

                continue;
            }

            $this->line("{$label}: ".count($plan).' object(s) to copy.');

            if ($dryRun) {
                // A per-zone tally rather than the first few keys: sorted alphabetically those are
                // always manifests and segments, so a misrouted image or master — or a whole
                // category silently left behind — would never appear in a sample.
                foreach ($this->tally($plan, $video) as $zone => $count) {
                    $this->line(sprintf('    %-10s %d', $zone, $count));
                }

                foreach (array_slice($this->unclassified($video), 0, 5) as $key) {
                    $this->warn("    not classified, staying put: {$key}");
                }

                $totalCopies += count($plan);

                continue;
            }

            if (! $this->copy($plan)) {
                $this->error("{$label}: copy failed, leaving it for a re-run.");
                $failed++;

                continue;
            }

            $this->rewriteOriginalPath($video);
            $totalCopies += count($plan);
        }

        $this->info(($dryRun ? 'Would copy ' : 'Copied ')."{$totalCopies} object(s).");

        if ($failed > 0) {
            $this->error("{$failed} video(s) failed. Nothing was deleted, so re-running is safe.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return Collection<int, Video> */
    private function targets()
    {
        $query = Video::query()->orderBy('id');

        if ($arguments = $this->argument('video')) {
            $query->where(fn ($q) => $q->whereIn('id', array_filter($arguments, 'is_numeric'))
                ->orWhereIn('ulid', $arguments));
        }

        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }

        return $query->get();
    }

    /**
     * Source key => destination key for everything of this video's still sitting in the flat layout.
     *
     * @return array<string, string>
     */
    private function plan(Video $video): array
    {
        $arrived = [];
        $flat = [];

        // One listing, used twice: the flat keys to move, and the sizes of whatever already sits in
        // a zone. That is what makes a re-run resumable — a copy interrupted at hour six picks up
        // where it stopped instead of starting over — and it costs nothing extra, because the
        // originals are never deleted and would otherwise be re-planned on every pass.
        foreach (Storage::disk('s3')->listContents($video->ulid, true) as $item) {
            if (! $item->isFile()) {
                continue;
            }

            $relative = substr($item->path(), strlen($video->ulid) + 1);

            if (in_array(strtok($relative, '/'), self::ZONES, true)) {
                $arrived[$relative] = $item->fileSize();

                continue;
            }

            $flat[$relative] = $item->fileSize();
        }

        $plan = [];

        foreach ($flat as $relative => $size) {
            $zone = $this->zoneFor($relative);

            if ($zone === null) {
                continue;
            }

            // Size, not mere presence: a copy that died mid-flight leaves a short object, and
            // skipping it would leave the video quietly broken in the new layout.
            if (($arrived["{$zone}/{$relative}"] ?? null) === $size) {
                continue;
            }

            $plan["{$video->ulid}/{$relative}"] = "{$video->ulid}/{$zone}/{$relative}";
        }

        return $plan;
    }

    /**
     * How many objects each zone receives, so a dry run shows the shape of the move instead of an
     * alphabetical sample of it.
     *
     * @param  array<string, string>  $plan
     * @return array<string, int>
     */
    private function tally(array $plan, Video $video): array
    {
        $counts = array_fill_keys(self::ZONES, 0);

        foreach ($plan as $destination) {
            $counts[strtok(substr($destination, strlen($video->ulid) + 1), '/')]++;
        }

        return array_filter($counts);
    }

    /**
     * Keys this command does not recognise. They are skipped by design, but a dry run has to say so
     * out loud: silence here reads as "everything is accounted for" when it may not be.
     *
     * @return list<string>
     */
    private function unclassified(Video $video): array
    {
        $orphans = [];

        foreach (Storage::disk('s3')->allFiles($video->ulid) as $key) {
            $relative = substr($key, strlen($video->ulid) + 1);

            if (! in_array(strtok($relative, '/'), self::ZONES, true) && $this->zoneFor($relative) === null) {
                $orphans[] = $key;
            }
        }

        return $orphans;
    }

    /**
     * Which zone a flat-layout key belongs to, or null for anything unrecognised — an unknown key
     * is left exactly where it is rather than guessed at.
     */
    private function zoneFor(string $relative): ?string
    {
        $head = strtok($relative, '/');
        $isDirectory = str_contains($relative, '/');

        // `{streamUlid}/…` is a segment directory; `{streamUlid}.{ext}` with no slash is the
        // retained original. Same 26 characters, told apart by the slash.
        if (Str::isUlid($head) && $isDirectory) {
            return self::PLAY;
        }

        if (! $isDirectory) {
            return match (true) {
                (bool) preg_match('/\.(mpd|m3u8)$/', $relative) => self::PLAY,
                str_starts_with($relative, 'thumbnail'), str_starts_with($relative, 'storyboard') => self::ASSETS,
                Str::isUlid(pathinfo($relative, PATHINFO_FILENAME)) => self::ORIGINAL,
                default => null,
            };
        }

        return in_array($head, ['video', 'audio', 'subtitle'], true) ? self::DOWNLOAD : null;
    }

    /**
     * Hands the copy list to s5cmd rather than looping in PHP: 1.1M objects at one round trip each
     * would take the better part of a day, where s5cmd runs its own worker pool over the same
     * server-side copies.
     *
     * `--metadata-directive COPY` is load-bearing and was verified against the provider: without
     * it a copied `.mpd` comes back as `binary/octet-stream`, which is not in the edge's
     * `secure_token_types`, so the edge stops re-signing the segments the manifest lists and every
     * player 403s. Silent, catalogue-wide, and only visible in playback.
     *
     * @param  array<string, string>  $plan
     */
    private function copy(array $plan): bool
    {
        $bucket = (string) config('filesystems.disks.s3.bucket');
        $lines = [];

        foreach ($plan as $source => $destination) {
            $lines[] = sprintf(
                'cp --metadata-directive COPY "s3://%s/%s" "s3://%s/%s"',
                $bucket, $source, $bucket, $destination,
            );
        }

        // No suffix on purpose: `tempnam()` CREATES the file it names, so appending an extension
        // would write to a different path and leak the original — one empty file per video, which
        // over a full catalogue is a thousand of them.
        $script = tempnam(sys_get_temp_dir(), 'relocate');
        file_put_contents($script, implode("\n", $lines)."\n");

        try {
            $endpoint = (string) config('filesystems.disks.s3.endpoint');
            $result = Process::timeout(3600)->run(
                's5cmd '.($endpoint ? '--endpoint-url='.escapeshellarg($endpoint).' ' : '')
                .'run '.escapeshellarg($script)
            );

            if (! $result->successful()) {
                Log::error('relocate-storage: s5cmd run failed', ['error' => $result->errorOutput()]);
            }

            return $result->successful();
        } finally {
            @unlink($script);
        }
    }

    /**
     * `stream.path` of a retained original is a real key, not just a filename — the retry guard, the
     * stream observer and the panel's delete all read it directly. Every other type resolves through
     * {@see Stream::storedPath}, which derives the zone, so only this one needs rewriting.
     */
    private function rewriteOriginalPath(Video $video): void
    {
        $original = $video->streams()->where('type', 'original')->first();

        if (! $original) {
            return;
        }

        // Built here rather than through `Stream::archivePath()`: on the build this runs against,
        // that method still returns the pre-zone location, so it would compare the old path with
        // itself and never rewrite.
        $extension = pathinfo($original->path, PATHINFO_EXTENSION);
        $destination = "{$video->ulid}/".self::ORIGINAL."/{$original->ulid}".($extension ? ".{$extension}" : '');

        if ($original->path !== $destination && Storage::disk('s3')->exists($destination)) {
            $original->update(['path' => $destination]);
        }
    }
}
