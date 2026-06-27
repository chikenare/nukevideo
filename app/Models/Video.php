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
            'last_heartbeat_at' => 'datetime',
        ];
    }

    /** Liveness signal beaten by every processing stage; a dead worker stops it and the reaper recovers the video. */
    public function heartbeat(): void
    {
        $this->forceFill(['last_heartbeat_at' => now()])->saveQuietly();
    }

    public function stagingKey(string $name): string
    {
        return "{$this->ulid}/final/{$name}";
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
        $this->outputs()->update(['status' => VideoStatus::FAILED->value]);

        activity('video')
            ->performedOn($this)
            ->causedBy($this->user)
            ->event('video_failed')
            ->log("Video processing failed: {$this->name}");

        WebhookDispatcher::forVideo('video.error', $this);
    }
}
