<?php

namespace App\Console\Commands;

use App\Models\Node;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class SyncNodes extends Command
{
    protected $signature = 'nodes:sync';

    protected $description = 'Sync node information and metrics from host machine';

    public function handle()
    {
        $hostname = gethostname();
        $location = env('NODE_LOCATION', 'local');

        $metrics = $this->getHostMetrics();
        $uptime = $this->getUptime();

        Node::updateOrCreate(
            ['name' => $hostname],
            [
                'type' => env('NODE_TYPE', 'worker'),
                'base_url' => config('node.base_url'),
                'location' => $location,
                'status' => 'running',
                'is_active' => true,
                'metrics' => $metrics,
                'uptime' => $uptime,
            ]
        );

        $this->info("Node '{$hostname}' synced successfully.");
    }

    private function getHostMetrics(): array
    {
        return [
            'cpu_percent' => $this->getCpuPercent(),
            'memory_usage' => $this->getMemoryUsage(),
            'memory_total' => $this->getMemoryTotal(),
            'disk_usage' => $this->getDiskUsage(),
            'disk_total' => $this->getDiskTotal(),
            'load_average' => $this->getLoadAverage(),
            'network_rx' => $this->getNetworkBytes('rx'),
            'network_tx' => $this->getNetworkBytes('tx'),
        ];
    }

    private function getCpuPercent(): float
    {
        $result = Process::run("grep 'cpu ' /proc/stat");

        if (! $result->successful()) {
            return 0;
        }

        $parts = preg_split('/\s+/', trim($result->output()));
        // user + nice + system + idle + iowait + irq + softirq + steal
        $idle = (int) ($parts[4] ?? 0);
        $total = array_sum(array_map('intval', array_slice($parts, 1)));

        if ($total === 0) {
            return 0;
        }

        return round((1 - $idle / $total) * 100, 2);
    }

    private function getMemoryUsage(): int
    {
        $result = Process::run("awk '/MemAvailable/ {available=$2} /MemTotal/ {total=$2} END {print (total - available) * 1024}' /proc/meminfo");

        return $result->successful() ? (int) trim($result->output()) : 0;
    }

    private function getMemoryTotal(): int
    {
        $result = Process::run("awk '/MemTotal/ {print $2 * 1024}' /proc/meminfo");

        return $result->successful() ? (int) trim($result->output()) : 0;
    }

    private function getDiskUsage(): int
    {
        $result = Process::run("df -B1 / | awk 'NR==2 {print $3}'");

        return $result->successful() ? (int) trim($result->output()) : 0;
    }

    private function getDiskTotal(): int
    {
        $result = Process::run("df -B1 / | awk 'NR==2 {print $2}'");

        return $result->successful() ? (int) trim($result->output()) : 0;
    }

    private function getLoadAverage(): array
    {
        $result = Process::run("cat /proc/loadavg");

        if (! $result->successful()) {
            return [0, 0, 0];
        }

        $parts = explode(' ', trim($result->output()));

        return [
            (float) ($parts[0] ?? 0),
            (float) ($parts[1] ?? 0),
            (float) ($parts[2] ?? 0),
        ];
    }

    private function getNetworkBytes(string $direction): int
    {
        $field = $direction === 'rx' ? 2 : 10;
        $result = Process::run("awk 'NR>2 && $1 !~ /lo:/ {sum += $" . $field . "} END {print sum+0}' /proc/net/dev");

        return $result->successful() ? (int) trim($result->output()) : 0;
    }

    private function getUptime(): string
    {
        $result = Process::run("cat /proc/uptime");

        if (! $result->successful()) {
            return 'unknown';
        }

        $seconds = (int) explode(' ', trim($result->output()))[0];
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);

        return "{$days}d {$hours}h";
    }
}
