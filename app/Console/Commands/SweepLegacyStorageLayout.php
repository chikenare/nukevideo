<?php

namespace App\Console\Commands;

use App\Models\Video;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes the flat-layout objects left behind by {@see RelocateStorageLayout}, once the zoned copy
 * has proven itself.
 *
 * This is the only destructive step of the migration, and the only one that cannot be undone by a
 * redeploy — so it verifies rather than trusts. A flat key is removed only when its counterpart in
 * the new layout exists AND reports the same byte count; anything that fails either check is left
 * alone and reported. Nothing inside a zone is ever a candidate.
 *
 * Run it days or weeks after the deploy, not minutes: until it runs, reverting the deploy is enough
 * to go back, and that is worth more than the duplicated storage it costs.
 */
class SweepLegacyStorageLayout extends Command
{
    protected $signature = 'videos:sweep-legacy-layout
        {video?* : Video ids or ULIDs; omit to sweep every video}
        {--dry-run : Report what would be deleted and delete nothing}
        {--limit= : Stop after this many videos}';

    protected $description = 'Delete the pre-zone objects of videos whose relocation is verified complete';

    private const ZONES = ['play', 'download', 'assets', 'original'];

    public function handle(): int
    {
        $videos = $this->targets();

        if ($videos->isEmpty()) {
            $this->info('Nothing to sweep.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->confirm("Delete the pre-zone objects of {$videos->count()} video(s)? This cannot be undone.")) {
            return self::SUCCESS;
        }

        $deleted = 0;
        $held = 0;

        foreach ($videos as $video) {
            $label = "Video {$video->id} ({$video->ulid})";
            [$safe, $unsafe] = $this->classify($video);

            if ($unsafe !== []) {
                // Partial or failed relocation. Deleting the verified half would still leave the
                // video broken, and would destroy the only remaining copy of the rest.
                $this->warn("{$label}: ".count($unsafe).' object(s) not verified in the new layout — skipping the whole video.');

                foreach (array_slice($unsafe, 0, 3) as $key) {
                    $this->line("    {$key}");
                }

                $held += count($safe) + count($unsafe);

                continue;
            }

            if ($safe === []) {
                $this->line("{$label}: nothing left from the old layout.");

                continue;
            }

            $this->line("{$label}: ".count($safe).' object(s) verified, '.($dryRun ? 'would delete' : 'deleting').'.');

            if (! $dryRun) {
                Storage::disk('s3')->delete($safe);
                Log::info('Swept pre-zone objects', ['video' => $video->id, 'objects' => count($safe)]);
            }

            $deleted += count($safe);
        }

        $this->info(($dryRun ? 'Would delete ' : 'Deleted ')."{$deleted} object(s).");

        if ($held > 0) {
            $this->warn("{$held} object(s) held back. Re-run videos:relocate-storage for those videos first.");
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
     * Splits this video's flat-layout keys into those safe to delete and those that are not.
     *
     * Safe means the same relative path exists under one of the zones with an identical size.
     * Existence alone would accept a truncated copy — the sizes come from the same listing, so the
     * stronger check is free.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function classify(Video $video): array
    {
        $sizes = [];
        $flat = [];

        foreach (Storage::disk('s3')->listContents($video->ulid, true) as $item) {
            if (! $item->isFile()) {
                continue;
            }

            $key = $item->path();
            $relative = substr($key, strlen($video->ulid) + 1);
            $head = strtok($relative, '/');

            if (in_array($head, self::ZONES, true)) {
                $sizes[$relative] = $item->fileSize();

                continue;
            }

            $flat[$relative] = $item->fileSize();
        }

        $safe = [];
        $unsafe = [];

        foreach ($flat as $relative => $size) {
            $copied = false;

            foreach (self::ZONES as $zone) {
                if (($sizes["{$zone}/{$relative}"] ?? null) === $size) {
                    $copied = true;
                    break;
                }
            }

            $copied
                ? $safe[] = "{$video->ulid}/{$relative}"
                : $unsafe[] = "{$video->ulid}/{$relative}";
        }

        return [$safe, $unsafe];
    }
}
