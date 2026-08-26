<?php

namespace App\Models;

use App\Enums\NodeAccel;
use App\Enums\NodeType;
use App\Observers\NodeObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Log;

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
        'is_draining',
        // Written by the probe and the deploy, never by the API: the Data objects do not expose them.
        'health_failures',
        'last_healthy_at',
        'is_storage_server',
        'storage_endpoint',
        'log',
        'env',
    ];

    protected function casts()
    {
        return [
            'type' => NodeType::class,
            'accel' => NodeAccel::class,
            'is_active' => 'boolean',
            'is_draining' => 'boolean',
            'is_storage_server' => 'boolean',
            'last_healthy_at' => 'datetime',
        ];
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

        // Traefik and Vector are deliberately absent, even though a proxy deploy raises them. They
        // serve every proxy on the host, not the one being acted on: Traefik owns ports 80 and 443
        // so a second instance could not exist anyway, and Vector reads the Docker socket rather
        // than any single container. Listing them here meant deactivating one proxy tore down TLS
        // and bandwidth accounting for its neighbours — and left them off after a reboot, since the
        // stop outlives it.
        //
        // The cost is that removing the last proxy from a host leaves both running idle, which is
        // the cheaper mistake.
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

    /**
     * Consecutive failed probes before a proxy stops receiving new playback links. The probe
     * runs every minute, so this is three minutes of silence — long enough that a single
     * slow answer or a restart does not move a node's whole catalogue, cold, onto its
     * neighbours, short enough that a dead node stops being handed viewers.
     */
    public const HEALTH_FAILURE_THRESHOLD = 3;

    public function scopeHealthy($query)
    {
        return $query->where('health_failures', '<', self::HEALTH_FAILURE_THRESHOLD);
    }

    /**
     * A proxy the resolver may put in a playback URL: active, answering probes, not being
     * drained, and with a hostname to put there at all — a proxy without one was being
     * chosen and rendered as `https:///...`.
     */
    public function scopeRoutable($query)
    {
        return $query->proxy()->active()->healthy()->where('is_draining', false)->whereNotNull('hostname');
    }

    public function isHealthy(): bool
    {
        return $this->health_failures < self::HEALTH_FAILURE_THRESHOLD;
    }

    /**
     * Query-builder writes on purpose: the observer reacts to `is_active`, and health must
     * never start or stop containers — and the probe updates many nodes a minute.
     */
    public function markHealthy(): void
    {
        static::whereKey($this->id)->update(['health_failures' => 0, 'last_healthy_at' => now()]);
        $this->health_failures = 0;
        $this->last_healthy_at = now();
    }

    public function markProbeFailed(): void
    {
        // Capped so a node that is down for a week is not a node that needs a week of good
        // probes — one success is what clears it.
        $failures = min($this->health_failures + 1, 255);
        static::whereKey($this->id)->update(['health_failures' => $failures]);
        $this->health_failures = $failures;
    }

    private const HASH_RING_REPLICAS = 150;

    /**
     * The proxy that serves this video, by consistent hashing over the routable proxies. Every
     * node shares the token secret, so any of them can serve any video; what the ring buys is
     * that a video's segments are cached on one node rather than on all of them.
     *
     * Falls back to every active proxy when none is routable: that is the probe being wrong
     * for the whole fleet (the API host losing its own network, say), and a link to a node
     * that may be down beats no link at all.
     */
    public static function findProxyForVideo(string $videoUlid): ?self
    {
        $nodes = static::routable()->orderBy('id')->get();

        if ($nodes->isEmpty()) {
            // Draining stays honoured: it is the operator's word, the probe's is only a guess.
            $nodes = static::proxy()->active()->where('is_draining', false)->whereNotNull('hostname')->orderBy('id')->get();

            if ($nodes->isEmpty()) {
                return null;
            }

            Log::warning('No routable proxy node; falling back to every active one', ['count' => $nodes->count()]);
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
