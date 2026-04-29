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
        ];
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
        if ($this->status === VideoStatus::FAILED->value) {
            return;
        }

        $this->update(['status' => VideoStatus::FAILED]);

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
