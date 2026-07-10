<?php

namespace App\Models;

use App\Enums\VideoStatus;
use App\Observers\VideoObserver;
use App\Services\WebhookDispatcher;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[ObservedBy(VideoObserver::class)]
class Video extends Model
{
    protected $fillable = [
        'template_id',
        'user_id',
        'project_id',
        'node_id',
        'name',
        'duration',
        'aspect_ratio',
        'chunk_count',
        'status',
        'external_user_id',
        'external_resource_id',
        'last_heartbeat_at',
    ];

    /** Non-terminal statuses: the video is still being processed. */
    public const ACTIVE_STATUSES = [
        VideoStatus::PENDING->value,
        VideoStatus::DOWNLOADING->value,
        VideoStatus::RUNNING->value,
        VideoStatus::UPLOADING->value,
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->ulid = (string) Str::ulid();
        });
    }

    public function casts()
    {
        return [
            'duration' => 'double',
            'chunk_count' => 'integer',
            'last_heartbeat_at' => 'datetime',
        ];
    }

    /** Liveness signal beaten by every processing stage; a dead worker stops it and the reaper recovers the video. */
    public function heartbeat(): void
    {
        $this->forceFill(['last_heartbeat_at' => now()])->saveQuietly();
    }

    /*
     * Storage layout — the sub-dir names (one source of truth) plus the few keys that are built in
     * more than one place. One-off keys (e.g. the mirrored source, a rendition) are inlined at their
     * single call site. Disk selection stays at the call site; these return keys only.
     */

    /** Sub-dir names used across the internal mirror ('chunks' disk) and local scratch. */
    public const SOURCE_DIR = 'source';

    public const CHUNKS_DIR = 'chunks';

    public const FINAL_DIR = 'final';

    public const GATHER_DIR = 'gather';

    public const CHUNKSTAGE_DIR = 'chunkstage';

    public const SIDECAR_DIR = 'sidecar';

    /** Filename of every video's thumbnail/storyboard assets, shared by the jobs that produce them
     *  and the controller/DTOs that serve/link them. */
    public const THUMBNAIL_FILENAME = 'thumbnail.jpg';

    public const STORYBOARD_VTT_FILENAME = 'storyboard.vtt';

    /** printf-style pattern ffmpeg fills in (`%d`) when emitting one sprite sheet per call; mirrors
     *  {@see storyboardSpriteFilename} so the writer and reader of these files never disagree. */
    public const STORYBOARD_SPRITE_PATTERN = 'storyboard_%d.jpg';

    public static function storyboardSpriteFilename(int $index): string
    {
        return sprintf(self::STORYBOARD_SPRITE_PATTERN, $index);
    }

    /** Glob matching every chunk filename `chunkKey()` produces, for jobs that need to enumerate
     *  staged chunks rather than address one by index. */
    public const CHUNK_FILENAME_GLOB = 'chunk_*.mp4';

    /** Internal-mirror key of one encoded chunk: `{ulid}/chunks/{streamUlid}/chunk_NNN.mp4` (built in two jobs). */
    public function chunkKey(Stream $stream, int $index): string
    {
        return sprintf('%s/%s/chunk_%03d.mp4', $this->chunksDir(), $stream->ulid, $index);
    }

    /** Internal-mirror dir holding every stream's staged chunks: `{ulid}/chunks` (synced/pruned by several jobs). */
    public function chunksDir(): string
    {
        return self::chunksDirFor($this->ulid);
    }

    /** Same as {@see chunksDir()} for call sites that only have the bare ulid (e.g. scratch-dir sweeps). */
    public static function chunksDirFor(string $ulid): string
    {
        return "{$ulid}/".self::CHUNKS_DIR;
    }

    /** Internal-mirror dir holding every single-pass staged asset: `{ulid}/final` (synced/pruned by several jobs). */
    public function finalDir(): string
    {
        return "{$this->ulid}/".self::FINAL_DIR;
    }

    /** Internal-mirror key of the mirrored source upload: `{ulid}/source/original.{ext}`. */
    public function sourceMirrorPath(string $extension): string
    {
        return "{$this->ulid}/".self::SOURCE_DIR."/original.{$extension}";
    }

    /** Local scratch path for one sidecar track before it's staged to the mirror: `{ulid}/sidecar/{streamUlid}.{ext}`. */
    public function sidecarPath(Stream $stream, string $extension): string
    {
        return "{$this->ulid}/".self::SIDECAR_DIR."/{$stream->ulid}.{$extension}";
    }

    /** Local scratch dir (relative) where renditions are concatenated and packaged. */
    public function gatherDir(): string
    {
        return "{$this->ulid}/".self::GATHER_DIR;
    }

    /** Local scratch dir (relative) where chunks are staged before concat. */
    public function chunkstageDir(): string
    {
        return "{$this->ulid}/".self::CHUNKSTAGE_DIR;
    }

    /** Primary-S3 key of a video-root asset (thumbnail/storyboard): `{ulid}/{filename}`. */
    public static function assetPath(string $ulid, string $filename): string
    {
        return "{$ulid}/{$filename}";
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function streams(): HasMany
    {
        return $this->hasMany(Stream::class);
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(Output::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    public function getSourceHeight(): ?int
    {
        return $this->streams()
            ->where('type', 'video')
            ->get()
            ->max(fn (Stream $s) => $s->meta['source_height'] ?? null);
    }

    public function markAsFailed(): void
    {
        // Guarded + atomic: only an active video may move to FAILED, so a reaper/prune sweep
        // can't clobber one a slow-but-alive worker just COMPLETED, nor double-fire the webhook.
        $transitioned = static::query()
            ->whereKey($this->id)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->update(['status' => VideoStatus::FAILED->value]);

        if (! $transitioned) {
            return;
        }

        $this->setAttribute('status', VideoStatus::FAILED->value);
        // Never clobber an output a concurrent success path already COMPLETED (mirrors PackageVideoJob::failed).
        $this->outputs()
            ->whereNotIn('status', [VideoStatus::COMPLETED->value, VideoStatus::FAILED->value])
            ->update(['status' => VideoStatus::FAILED->value]);

        activity('video')
            ->performedOn($this)
            ->causedBy($this->user)
            ->event('video_failed')
            ->log("Video processing failed: {$this->name}");

        WebhookDispatcher::forVideo('video.error', $this);
    }
}
