<?php

namespace App\Models;

use App\Services\Concerns\BuildsArguments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Template extends Model
{
    use BuildsArguments;

    protected $fillable = [
        'name',
        'query',
        'keep_processed_files',
        'keep_original',
        'user_id',
        'project_id',
    ];

    protected function casts()
    {
        return [
            'query' => 'json',
            'keep_processed_files' => 'boolean',
            'keep_original' => 'boolean',
        ];
    }

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

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function scopeFindOrFailByUlid(Builder $query, $ulid)
    {
        return $query->where('ulid', $ulid)->firstOrFail();
    }

    /** Preview ffmpeg commands for each output variant of this template. */
    public function buildCommands(): array
    {
        $commands = [];

        foreach ($this->query['outputs'] ?? [] as $output) {
            $codec = $output['video_codec'] ?? null;

            foreach ($output['variants'] ?? [] as $variant) {
                $args = [];

                if ($codec) {
                    $args[] = "-c:v {$codec}";
                }

                $width = $variant['width'] ?? 0;
                $height = $variant['height'] ?? 0;
                if ($width > 0 && $height > 0) {
                    $args[] = "-vf scale={$width}:{$height}";
                }

                $args = array_merge($args, $this->buildParamsArguments($variant, 'video'));

                $audio = $output['audio'] ?? [];
                $audioCodec = $audio['audio_codec'] ?? null;
                if ($audioCodec) {
                    $args[] = "-c:a {$audioCodec}";
                }

                $channel = $audio['channels'][0] ?? [];
                $args = array_merge($args, $this->buildParamsArguments($channel, 'audio'));

                $argString = implode(' ', $args);
                $commands[] = "ffmpeg -hide_banner -y -i \"input\" {$argString} \"output\"";
            }
        }

        return $commands;
    }
}
