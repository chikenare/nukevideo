<?php

namespace App\Models;

use App\Enums\NodeAccel;
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
        'accel',
        'hostname',
        'is_active',
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
            'accel' => NodeAccel::class,
            'is_active' => 'boolean',
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

    /**
     * The docker namespace this installation owns. Development and production are separate
     * databases with separate id sequences, so both call their first worker `nukevideo_worker_1` —
     * and a machine is routinely both a production node and the development host at once. Without
     * the split, deploying the dev node would `docker rm -f` the *running production worker* and
     * hand the fleet's chunk volume to the dev store. The workdir has always been keyed by uuid;
     * this is the rest of what a deploy names.
     */
    public static function containerPrefix(): string
    {
        return app()->isLocal() ? 'nukevideo_dev' : 'nukevideo';
    }

    /**
     * The container that makes this node do its job — the Horizon worker on a worker node, Traefik's
     * companion on a proxy. Built from the type and the id only, so it can never be confused with
     * the containers that serve the whole fleet from this host: the storage container is the chunk
     * store every other node reads and writes through, and Vector ships the edge logs. Stopping the
     * node's own service takes it out of rotation; stopping the others takes the cluster down.
     */
    public function serviceContainerName(): string
    {
        return self::containerPrefix()."_{$this->type->value}_{$this->id}";
    }

    /**
     * The chunk store this node hosts, if it is flagged as the storage server. Named apart from the
     * service container on purpose, and deliberately absent from {@see deployedContainerNames()}:
     * it is the one container on a node that the *whole fleet* depends on — every other node's
     * `chunks` disk reads and writes through it. Taking a node out of rotation must not take it
     * down; deleting the node does, and then another node has to be flagged as storage server.
     */
    public function storageContainerName(): string
    {
        return self::containerPrefix()."_storage_{$this->id}";
    }

    /**
     * Every container this node's deploy raises on its host and that serves this node alone.
     *
     * Listed unconditionally, whether or not the current settings would still create each one: a
     * `docker stop` on a name that is not there is a no-op, so this never has to reconstruct which
     * CDN provider or which environment the deploy ran under. That matters most for the ones a
     * settings change can orphan — Vector stops being deployed the moment the CDN switches to
     * Bunny, and a node deactivated afterwards would otherwise leave it running forever.
     */
    public function deployedContainerNames(): array
    {
        $names = [$this->serviceContainerName()];

        if ($this->type === NodeType::PROXY) {
            // A proxy deploy raises both, and both exist only to serve that proxy: Traefik fronts
            // it and terminates its TLS, Vector ships its access logs to the bandwidth pipeline.
            $names[] = self::containerPrefix().'_traefik';
            $names[] = self::containerPrefix().'_vector';
        }

        return $names;
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
