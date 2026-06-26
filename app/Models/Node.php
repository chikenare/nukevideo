<?php

namespace App\Models;

use App\Enums\NodeType;
use App\Observers\NodeObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy(NodeObserver::class)]
class Node extends Model
{
    protected $fillable = [
        'name',
        'user',
        'ip_address',
        'type',
        'hostname',
        'is_active',
        'cdn_mode',
        'is_storage_server',
        'storage_endpoint',
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
            'is_storage_server' => 'boolean',
        ];
    }

    public function sshKey(): BelongsTo
    {
        return $this->belongsTo(SshKey::class);
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

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
}
