<?php

namespace App\Models;

use App\Enums\OutputFormat;
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
        'format',
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
            'format' => OutputFormat::class,
            'status' => VideoStatus::class,
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

    /**
     * Encode progress lives in a Redis hash (field per chunk index = percent), not a DB column:
     * it's a transient UX value. Tracked per output since every rendition shares the same chunks.
     */
    private static function chunkProgressKey(int $outputId): string
    {
        return "output-progress:{$outputId}";
    }

    /** Seed `$total` chunks at 0% so the averaged percent has a stable divisor from tick one. */
    public function seedChunkProgress(int $total): void
    {
        if ($total < 1) {
            return;
        }

        $key = self::chunkProgressKey($this->id);
        $seed = [];
        for ($i = 0; $i < $total; $i++) {
            $seed[(string) $i] = 0;
        }

        Redis::hmset($key, $seed);
        Redis::expire($key, 86400); // 24h
    }

    /** HSET per chunk field, so retries are idempotent and parallel workers never clobber. */
    public function reportChunkProgress(int $chunkIndex, int $percent): void
    {
        Redis::hset(self::chunkProgressKey($this->id), (string) $chunkIndex, max(0, min(100, $percent)));
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
