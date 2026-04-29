<?php

namespace App\Models;

use App\Observers\StreamObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

#[ObservedBy(StreamObserver::class)]
class Stream extends Model
{
    protected $fillable = [
        'video_id',
        'name',
        'path',
        'type',
        'size',
        'meta',
        'status',

        'input_params',

        'width',
        'height',
        'language',
        'channels',

        'progress',
        'started_at',
        'completed_at',

        'error_log',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            $model->ulid = (string) Str::ulid();
        });
    }

    protected function casts()
    {
        return [
            'meta' => 'array',
            'input_params' => 'array',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function outputs(): BelongsToMany
    {
        return $this->belongsToMany(Output::class);
    }
}
