<?php

namespace App\Services;

use App\Models\Node;

/**
 * The edge cache disks of a proxy node: finding them, formatting them and mounting them.
 *
 * The bash itself is `resources/deploy/cache-disks.sh`; this class holds the constants the
 * rest of the application needs to agree with it, and the panel's read-only listing.
 *
 * A proxy node is dedicated hardware: a small NVMe (or a mirrored pair) carrying the OS and every
 * other disk meant for the segment cache, whole. So the deploy provisions those disks itself —
 * one mdadm RAID 0 across all of them (plain XFS when there is only one), mounted at a fixed path —
 * instead of asking the operator to prepare a filesystem and point the node at it.
 *
 * RAID 0 on purpose. The cache is a copy of what S3 holds, so redundancy would buy nothing but
 * lost capacity and write amplification; what it needs is throughput, and one spindle cannot
 * feed a 10 Gbps port. Linux md stripes unequal members by zones, so mixed sizes lose nothing.
 * A dead member loses the whole cache of that node, which the node survives: every miss is an
 * origin fetch until the disk is replaced and the node redeployed.
 *
 * What it must never do is format the wrong disk. Every block device that holds a mountpoint,
 * swap, or a RAID/LVM member whose assembled volume is mounted is the system's and is excluded
 * — which is how the OS disks are recognised, rather than by "NVMe means OS", because a cache
 * disk may be NVMe too. A disk already carrying this cache is assembled and mounted as it is: a
 * redeploy, which is how a node is updated, keeps the cache. Anything else among the remaining
 * disks is wiped, which is why the panel lists them and asks before a deploy runs, and why a
 * development panel never provisions at all — its deploy target is usually the developer's own
 * machine, where an unmounted disk is a backup, not a spare.
 */
class ProxyCacheService
{
    /** Host mount point of the cache pool. Fixed: every proxy node looks the same. */
    public const MOUNT = '/var/lib/nukevideo/cache';

    /** Path the edge nginx caches into ({@see vod/nginx/nginx.conf.template}). */
    public const CONTAINER_PATH = '/var/cache/nginx/vod';

    /**
     * Filesystem label and md array name; they are how a redeploy recognises its own pool. mdadm
     * stores the name as `{homehost}:{name}`, which is why the RAID member check below matches
     * on the suffix — and why the name carries no colon of its own, or udev would publish the
     * array under a different `/dev/md/` path on every host whose name is not the one in the
     * superblock.
     */
    public const FS_LABEL = 'nukevideo-cache';

    public const MD_DEVICE = '/dev/md/nukevideo-cache';

    /**
     * `max_size` handed to the edge when it is caching into a docker volume on the OS disk —
     * development, or a production host found without a single spare disk — rather than a pool
     * it may fill. The entrypoint sizes the cache to the filesystem only when this is absent.
     */
    public const FALLBACK_MAX_SIZE = '10g';

    public function __construct(private SSHService $ssh, private SshKeyService $sshKeys) {}

    /**
     * The host directory this node's edge caches into. One sub-directory per node and per
     * environment under the shared mount, like the container names, so a development proxy
     * deployed beside a production one on the same host never reads or evicts its cache.
     */
    public static function directoryFor(Node $node): string
    {
        return self::MOUNT.'/'.$node->serviceContainerName();
    }

    /** The docker volume used when there is no pool; removed with the node. */
    public static function volumeFor(Node $node): string
    {
        return Node::containerPrefix()."_proxy_cache_{$node->id}";
    }

    /**
     * What a deploy would do to the node's disks, read over SSH without changing anything.
     *
     * @return array<int, array{device: string, size: int, model: string, state: string, detail: string}>
     */
    public function inventory(Node $node): array
    {
        $output = $this->ssh->run(
            ip: $node->ip_address,
            user: $node->user,
            privateKey: $this->sshKeys->privateKey(),
            command: 'bash -s',
            timeout: 30,
            // `blkid -p` reads the raw device, which needs root; without it a partitioned disk
            // with no filesystem would pass for an empty one. The deploy's own sudo preamble
            // goes first, so a user whose sudo wants a password fails here, loudly and with the
            // same fix message, instead of the inventory quietly calling that disk `empty`.
            input: NodeService::deployScript('sudo')."\n".NodeService::deployScript('cache-disks')."\ncache_disk_inventory\n",
        );

        $disks = [];
        // Newlines only: the detail is the last field and empty for a blank disk, so a plain
        // trim() ate the trailing tab of the last line and dropped exactly the disk that matters.
        foreach (explode("\n", trim($output, "\r\n")) as $line) {
            $fields = explode("\t", rtrim($line, "\r"));
            if (count($fields) !== 5) {
                continue;
            }
            [$device, $size, $model, $state, $detail] = $fields;
            $disks[] = ['device' => $device, 'size' => (int) $size, 'model' => $model, 'state' => $state, 'detail' => $detail];
        }

        return $disks;
    }
}
