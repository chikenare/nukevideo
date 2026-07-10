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
        'package_size',
        'file_size',
        'meta',

        'input_params',

        'width',
        'height',
        'language',
        'channels',

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
            'package_size' => 'integer',
            'file_size' => 'integer',
        ];
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    /** This stream's `path` with the leading `{videoUlid}/` segment stripped, e.g. `video/{ulid}.mp4`.
     *  String-derived (no `video` relation load) since the prefix is already encoded in `path`. */
    public function relativePath(): string
    {
        return preg_replace('#^[^/]+/#', '', $this->path, 1);
    }

    /** Internal-mirror staging key for this rendition: `{videoUlid}/final/{type}/{ulid}.{ext}`. */
    public function stagingPath(): string
    {
        return strstr($this->path, '/', true).'/'.Video::FINAL_DIR.'/'.$this->relativePath();
    }

    /** This stream's packaged CMAF segment directory on primary S3: `{videoUlid}/{streamUlid}`.
     *  Matches the layout the packager writes into ({@see \App\Services\PackagerCommandBuilder}). */
    public function segmentsPath(Video $video): string
    {
        return "{$video->ulid}/{$this->ulid}";
    }

    /** Where a retained `original` is filed once processing ends: `{videoUlid}/{streamUlid}.{ext}`,
     *  alongside the video's own prefix instead of the shared upload folder. The unguessable stream
     *  ULID is what keeps it undownloadable: the CDN token authorizes all of `{videoUlid}/`, but no
     *  manifest ever names this stream. */
    public function archivePath(Video $video): string
    {
        $extension = pathinfo($this->path, PATHINFO_EXTENSION);

        return "{$video->ulid}/{$this->ulid}".($extension ? ".{$extension}" : '');
    }

    public function outputs(): BelongsToMany
    {
        return $this->belongsToMany(Output::class);
    }
}
