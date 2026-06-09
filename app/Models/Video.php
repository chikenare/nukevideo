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
        'status',
        'external_user_id',
        'external_resource_id',
        'last_heartbeat_at',
        'dispatch_attempts',
    ];

    /**
     * Statuses in which a video is actively being processed (non-terminal).
     */
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
            'last_heartbeat_at' => 'datetime',
        ];
    }

    /**
     * Refresh this video's liveness signal and keep its node slot alive.
     *
     * Called periodically by every processing stage. A worker/node that dies
     * stops heartbeating, which lets the reaper detect and recover the video fast
     * instead of it hanging until the queue's retry_after (~6h) elapses.
     */
    public function heartbeat(): void
    {
        $this->forceFill(['last_heartbeat_at' => now()])->saveQuietly();

        if ($this->node_id) {
            Node::find($this->node_id)?->touchSlot($this->id);
        }
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
            ->whereIn('type', ['video', 'muxed'])
            ->get()
            ->max(fn (Stream $s) => $s->meta['source_height'] ?? null);
    }

    public function markAsFailed(): void
    {
        // Guarded terminal transition: only an actively-processing video may move
        // to FAILED. This stops a reaper/prune sweep (or a superseded chain) from
        // clobbering a video a slow-but-alive worker just COMPLETED, and makes the
        // transition atomic so concurrent callers can't double-fire the webhook.
        $transitioned = static::query()
            ->whereKey($this->id)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->update(['status' => VideoStatus::FAILED->value]);

        if (! $transitioned) {
            return;
        }

        $this->setAttribute('status', VideoStatus::FAILED->value);

        $this->streams()
            ->whereIn('status', [VideoStatus::PENDING->value, VideoStatus::RUNNING->value])
            ->update(['status' => VideoStatus::FAILED->value]);

        activity('video')
            ->performedOn($this)
            ->causedBy($this->user)
            ->event('video_failed')
            ->log("Video processing failed: {$this->name}");

        WebhookDispatcher::forVideo('video.error', $this);
    }
}
