<?php

namespace App\Models;

use App\Enums\NodeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Redis;

class Node extends Model
{
    protected $fillable = [
        'name',
        'user',
        'ip_address',
        'type',
        'workers',
        'hostname',
        'is_active',
        'cdn_mode',
        'ssh_key_id',
        'log',
        'env',
    ];

    protected function casts()
    {
        return [
            'type' => NodeType::class,
            'is_active' => 'boolean',
            'cdn_mode' => 'boolean',
            'metrics' => 'array',
        ];
    }

    private const SLOT_TTL = 7200; // 2 hours

    public function reserveSlot(int $videoId): void
    {
        Redis::zadd("node:{$this->id}:videos", now()->timestamp, $videoId);
    }

    /**
     * Refresh a reserved slot's timestamp so it is not reclaimed by the TTL while
     * the job is still making progress. Driven by {@see Video::heartbeat()}.
     */
    public function touchSlot(int $videoId): void
    {
        Redis::zadd("node:{$this->id}:videos", now()->timestamp, $videoId);
    }

    public function releaseSlot(int $videoId): void
    {
        Redis::zrem("node:{$this->id}:videos", $videoId);
    }

    public function activeVideoIds(): array
    {
        $this->cleanExpiredSlots();

        return Redis::zrange("node:{$this->id}:videos", 0, -1) ?: [];
    }

    public function availableWorkers(): int
    {
        $this->cleanExpiredSlots();

        return $this->workers - (int) Redis::zcard("node:{$this->id}:videos");
    }

    private function cleanExpiredSlots(): void
    {
        Redis::zremrangebyscore(
            "node:{$this->id}:videos",
            '-inf',
            now()->subSeconds(self::SLOT_TTL)->timestamp
        );
    }

    public function sshKey(): BelongsTo
    {
        return $this->belongsTo(SshKey::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    /**
     * Scope to get only active nodes
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeProxy($query)
    {
        return $query->where('type', 'proxy');
    }

    public function scopeWorker($query)
    {
        return $query->where('type', 'worker');
    }

    public static function findAvailableNode(): ?self
    {
        return static::active()->worker()->get()
            ->filter(fn (self $node) => $node->isAlive() && $node->availableWorkers() > 0)
            ->sortByDesc(fn (self $node) => $node->availableWorkers())
            ->first();
    }

    /**
     * Whether the node is reporting (so we don't dispatch into a black hole when
     * it has powered off). Liveness is derived from the freshness of the Vector
     * metrics it pushes — independent of how busy its workers are, and separate
     * from the manual {@see $is_active} switch.
     *
     * Fail-open: a node we've never seen metrics from is treated as alive (Vector
     * may simply not be wired up); only a node that WAS reporting and went silent
     * is treated as dead. Driven by {@see \App\Console\Commands\CheckNodeHealthCommand}.
     */
    public function isAlive(): bool
    {
        $redis = Redis::connection('vector');

        if ((bool) $redis->exists("node_health:{$this->id}")) {
            return true;
        }

        return ! (bool) $redis->exists("node_metrics_len:{$this->id}");
    }

    private const HASH_RING_REPLICAS = 150;

    public static function findProxyForVideo(string $videoUlid): ?self
    {
        $nodes = static::proxy()->active()->orderBy('id')->get();

        if ($nodes->isEmpty()) {
            return null;
        }

        if ($nodes->count() === 1) {
            return $nodes->first();
        }

        $ring = [];
        foreach ($nodes as $node) {
            for ($i = 0; $i < self::HASH_RING_REPLICAS; $i++) {
                $point = hexdec(substr(md5("{$node->id}:{$i}"), 0, 8));
                $ring[$point] = $node;
            }
        }
        ksort($ring);

        $hash = hexdec(substr(md5($videoUlid), 0, 8));

        foreach ($ring as $point => $node) {
            if ($hash <= $point) {
                return $node;
            }
        }

        return reset($ring);
    }

    public function resolveQueue(): string
    {
        return "streams-node-{$this->id}";
    }
}
