<?php

namespace App\Console\Commands;

use App\Models\Node;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class SyncNodes extends Command
{
    protected $signature = 'nodes:sync';

    protected $description = 'Sync nodes information and metrics from Docker containers';

    public function handle()
    {
        $containers = $this->getContainers();

        if (empty($containers)) {
            $this->warn('No containers found.');
            return;
        }

        $location = env('NODE_LOCATION', 'local');

        foreach ($containers as $container) {
            $name = ltrim($container['Names'][0] ?? 'unknown', '/');
            $containerId = $container['Id'];
            $state = $container['State'] ?? 'unknown';
            $uptime = $container['Status'] ?? null;

            $type = $this->resolveNodeType($container['Image'] ?? '');
            $stats = $state === 'running' ? $this->getContainerStats($containerId) : [];

            Node::updateOrCreate(
                ['container_id' => $containerId],
                [
                    'name' => $name,
                    'type' => $type,
                    'host' => env('HOSTNAME', gethostname()),
                    'location' => $location,
                    'status' => $state,
                    'is_active' => $state === 'running',
                    'metrics' => $stats ?: null,
                    'uptime' => $uptime,
                ]
            );
        }
    }

    private function getContainers(): array
    {
        $filter = http_build_query([
            'filters' => json_encode([
                'ancestor' => ['nukevideo-worker', 'nukevideo-cdn'],
            ]),
        ]);

        $process = Process::run(
            "curl -s --unix-socket /var/run/docker.sock 'http://localhost/containers/json?all=true&{$filter}'"
        );

        if (! $process->successful()) {
            $this->error('Failed to list containers: ' . $process->errorOutput());
            return [];
        }

        return json_decode($process->output(), true) ?: [];
    }

    private function getContainerStats(string $containerId): array
    {
        $process = Process::run(
            "curl -s --unix-socket /var/run/docker.sock 'http://localhost/containers/{$containerId}/stats?stream=false'"
        );

        if (! $process->successful()) {
            return [];
        }

        $stats = json_decode($process->output(), true);

        if (! $stats) {
            return [];
        }

        return [
            'cpu_percent' => $this->calculateCpuPercent($stats),
            'memory_usage' => $stats['memory_stats']['usage'] ?? 0,
            'memory_limit' => $stats['memory_stats']['limit'] ?? 0,
            'disk_read' => $this->sumBlkioField($stats, 'Read'),
            'disk_write' => $this->sumBlkioField($stats, 'Write'),
            'network_rx' => $this->sumNetworkField($stats, 'rx_bytes'),
            'network_tx' => $this->sumNetworkField($stats, 'tx_bytes'),
        ];
    }

    private function calculateCpuPercent(array $stats): float
    {
        $cpuDelta = ($stats['cpu_stats']['cpu_usage']['total_usage'] ?? 0)
            - ($stats['precpu_stats']['cpu_usage']['total_usage'] ?? 0);

        $systemDelta = ($stats['cpu_stats']['system_cpu_usage'] ?? 0)
            - ($stats['precpu_stats']['system_cpu_usage'] ?? 0);

        $cpuCount = $stats['cpu_stats']['online_cpus'] ?? 1;

        if ($systemDelta > 0 && $cpuDelta > 0) {
            return round(($cpuDelta / $systemDelta) * $cpuCount * 100, 2);
        }

        return 0;
    }

    private function sumBlkioField(array $stats, string $op): int
    {
        $entries = $stats['blkio_stats']['io_service_bytes_recursive'] ?? [];

        if (! is_array($entries)) {
            return 0;
        }

        return collect($entries)
            ->where('op', $op)
            ->sum('value');
    }

    private function sumNetworkField(array $stats, string $field): int
    {
        $networks = $stats['networks'] ?? [];

        return collect($networks)->sum($field);
    }

    private function resolveNodeType(string $image): string
    {
        if (str_contains($image, 'cdn') || str_contains($image, 'proxy')) {
            return 'proxy';
        }

        return 'worker';
    }
}
