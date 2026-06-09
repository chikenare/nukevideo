<?php

namespace App\Console\Commands;

use App\Models\Node;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

/**
 * Tracks worker-node liveness so the dispatcher never sends videos to a node that
 * has powered off (which would otherwise pile jobs onto a queue nobody consumes
 * until they exhaust retry attempts and fail).
 *
 * Signal: each node's Vector container pushes system metrics to the Redis list
 * `node_metrics:{id}` (~one entry/minute), independent of how busy its workers
 * are. While that list keeps growing the node is up; when it stops, the node is
 * down. We translate "growing" into a short-lived `node_health:{id}` key that
 * {@see Node::isAlive()} reads. This is intentionally decoupled from the manual
 * `is_active` admin switch.
 */
class CheckNodeHealthCommand extends Command
{
    protected $signature = 'nodes:health';

    protected $description = 'Mark worker nodes alive/dead from Vector metric freshness so dispatch skips dead nodes';

    // Generous relative to Vector's ~60s push interval, so transient jitter or a
    // single missed push doesn't flap a healthy node out of rotation.
    private const ALIVE_TTL = 180;

    public function handle(): void
    {
        $redis = Redis::connection('vector');

        foreach (Node::worker()->get() as $node) {
            $metricsKey = "node_metrics:{$node->id}";

            // Never reported → leave it unknown; Node::isAlive() fails open.
            if (! $redis->exists($metricsKey)) {
                continue;
            }

            $len = (int) $redis->llen($metricsKey);
            $prev = $redis->get("node_metrics_len:{$node->id}");

            $reporting = ($prev === false || $prev === null) || $len > (int) $prev;

            if ($reporting) {
                $redis->setex("node_health:{$node->id}", self::ALIVE_TTL, 1);
            }

            $redis->set("node_metrics_len:{$node->id}", $len);
        }
    }
}
