<?php

namespace App\Models;

use App\Observers\VideoObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[ObservedBy(VideoObserver::class)]
class Video extends Model
{
    protected $fillable = [
        'template_id',
        'user_id',
        'node_id',
        'name',
        'duration',
        'aspect_ratio',
        'thumbnail_path',
        'output_format',
        'status',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->ulid = (string) Str::ulid();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function streams()
    {
        return $this->hasMany(Stream::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
