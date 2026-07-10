<?php

namespace App\Models;

use App\Enums\VideoStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class Output extends Model
{
    protected $fillable = [
        'video_id',
        'status',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->ulid = (string) Str::ulid();
        });
    }

    protected function casts(): array
    {
        return [
            'status' => VideoStatus::class,
            'packaged_formats' => 'array',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function streams(): BelongsToMany
    {
        return $this->belongsToMany(Stream::class);
    }

    public function packagePrefix(): string
    {
        return $this->video->ulid;
    }

    /**
     * This output's manifest filename for a format, optionally capped to a height: `{ulid}.m3u8` /
     * `{ulid}.720.m3u8` (HLS) or `{ulid}.mpd` / `{ulid}.720.mpd` (DASH). Keyed by the output's own
     * ulid (not a fixed `master`/`manifest` name) so two outputs of the same video — which can land
     * on the same cap if their video renditions share a height — never collide on the same S3 key;
     * segments stay shared at `{videoUlid}/{streamUlid}/…` regardless, only the manifest is scoped
     * per output. Shared with {@see \App\Services\PackagerCommandBuilder} so the packager output and
     * the served path can never diverge.
     */
    public function manifestFile(string $format, ?int $cap = null): string
    {
        $ext = $format === 'hls' ? 'm3u8' : 'mpd';

        return $cap === null ? "{$this->ulid}.{$ext}" : "{$this->ulid}.{$cap}.{$ext}";
    }

    /** Public path of this output's manifest, mirroring its S3 key (`{videoUlid}/file`), optionally capped. */
    public function manifestPath(string $format, ?int $cap = null): string
    {
        return "{$this->packagePrefix()}/".$this->manifestFile($format, $cap);
    }

    /** Manifest cap for the max resolution a client can play: tallest packaged height <= $resolution
     *  (shortest if below all of them). Null (full master) when unset, >= max, or the tallest is max. */
    public function resolveCap(?int $resolution): ?int
    {
        if ($resolution === null) {
            return null;
        }

        $heights = $this->streams
            ->where('type', 'video')
            ->pluck('height')
            ->filter()
            ->map(fn ($height) => (int) $height)
            ->unique()
            ->sort()
            ->values();

        if ($heights->isEmpty() || $resolution >= $heights->last()) {
            return null;
        }

        $target = $heights->filter(fn (int $height) => $height <= $resolution)->last()
            ?? $heights->first();

        // The max height's manifest is the uncapped master (no `.{height}.` file exists for it).
        return $target === $heights->last() ? null : $target;
    }

    /**
     * Streaming formats this output actually serves. Frozen in `packaged_formats` at package time
     * ({@see recordFormats}) rather than recomputed live: a later stream deletion edits manifests in
     * place but never deletes the file ({@see \App\Services\ManifestEditor::removeStream}), so a live
     * recomputation from the currently-attached streams' codecs could silently drift from what's
     * really packaged on S3 — e.g. deleting the only Opus (DASH-only) audio stream from an output
     * would make a live computation claim HLS too, even though no `.m3u8` was ever packaged, breaking
     * {@see \App\Http\Controllers\VodController::buildLink}. Falls back to the live computation only
     * before packaging has run (`packaged_formats` is still null).
     *
     * @return list<string>
     */
    public function formats(): array
    {
        return $this->packaged_formats ?? $this->computedFormats();
    }

    /**
     * Live computation from this output's streams' codecs: the intersection of each codec's
     * supported protocols (config/ffmpeg.php). One CMAF package emits a manifest per format over
     * shared segments, so e.g. H.264+AAC yields both HLS and DASH, Opus only DASH. Authoritative only
     * at package time ({@see \App\Jobs\PackageVideoJob::packageOutput}, which freezes the result via
     * {@see recordFormats}); use {@see formats()} everywhere else.
     *
     * @return list<string>
     */
    public function computedFormats(): array
    {
        $codecs = collect(config('ffmpeg.codecs'));

        $protocolSets = $this->streams
            ->filter(fn (Stream $stream) => in_array($stream->type, ['video', 'audio'], true))
            ->map(fn (Stream $stream) => $codecs->firstWhere('codec', $this->streamCodec($stream))['protocols'] ?? [])
            ->filter()
            ->values();

        if ($protocolSets->isEmpty()) {
            return [];
        }

        return array_values(array_intersect(...$protocolSets->all()));
    }

    /** Freeze the formats actually packaged so {@see formats()} never has to recompute (and can't
     *  drift) after. Call once packaging for this output has genuinely finished. */
    public function recordFormats(array $formats): void
    {
        $this->forceFill(['packaged_formats' => $formats])->save();
    }

    private function streamCodec(Stream $stream): ?string
    {
        return match ($stream->type) {
            'video' => data_get($stream->input_params, 'video_codec'),
            'audio' => data_get($stream->input_params, 'audio_codec', 'aac'),
            default => null,
        };
    }

    /**
     * Encode progress lives in a Redis hash (field per chunk index = percent), not a DB column:
     * it's a transient UX value. Tracked per output since every rendition shares the same chunks.
     */
    private static function chunkProgressKey(int $outputId): string
    {
        return "output-progress:{$outputId}";
    }

    /** Seed one field per (chunk × rendition) at 0% so the averaged percent has a stable divisor
     *  from tick one. `$streamIds` are this output's video stream ids. */
    public function seedChunkProgress(int $chunks, array $streamIds): void
    {
        if ($chunks < 1 || empty($streamIds)) {
            return;
        }

        $key = self::chunkProgressKey($this->id);
        $seed = [];
        for ($i = 0; $i < $chunks; $i++) {
            foreach ($streamIds as $streamId) {
                $seed["{$i}:{$streamId}"] = 0;
            }
        }

        Redis::hmset($key, $seed);
        Redis::expire($key, 86400); // 24h
    }

    /** HSET per (chunk, rendition) field, so retries are idempotent and parallel workers never
     *  clobber. The TTL is refreshed on every report so an active pipeline never loses the hash,
     *  while an abandoned one still expires (a report can't resurrect an expired key forever). */
    public function reportChunkProgress(int $chunkIndex, int $streamId, int $percent): void
    {
        $key = self::chunkProgressKey($this->id);

        Redis::hset($key, "{$chunkIndex}:{$streamId}", max(0, min(100, $percent)));
        Redis::expire($key, 86400);
    }

    /**
     * Average percent across seeded chunks. Falls back to status once the hash is gone, so a
     * finished output reads 100 rather than 0.
     */
    public function progress(): int
    {
        $values = Redis::hvals(self::chunkProgressKey($this->id));

        if ($values === []) {
            return $this->status === VideoStatus::COMPLETED ? 100 : 0;
        }

        return (int) min(100, round(array_sum(array_map('intval', $values)) / count($values)));
    }

    public function clearChunkProgress(): void
    {
        Redis::del(self::chunkProgressKey($this->id));
    }
}
