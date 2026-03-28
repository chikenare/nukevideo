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
        'instances',
        'hostname',
        'is_active',
        'ssh_key_id',
        'log',
    ];

    protected function casts()
    {
        return [
            'type' => NodeType::class,
            'instances' => 'array',
            'is_active' => 'boolean',
            'metrics' => 'array',
        ];
    }

    public function getWorkloads(): array
    {
        return collect($this->instances ?? [])
            ->pluck('workload')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Get all videos processed by this node
     */
    public function getJobCounts(): array
    {
        $queues = [];
        $totalPending = 0;
        $totalReserved = 0;

        $workloads = $this->getWorkloads() ?: ['light', 'medium', 'heavy'];

        foreach ($workloads as $workload) {
            $queueName = "streams-node-{$this->id}-{$workload}";
            $pending = (int) Redis::llen("queues:{$queueName}");
            $reserved = (int) Redis::zcard("queues:{$queueName}:reserved");

            $queues[] = [
                'queue' => $queueName,
                'workload' => $workload,
                'pending' => $pending,
                'reserved' => $reserved,
            ];

            $totalPending += $pending;
            $totalReserved += $reserved;
        }

        return [
            'queues' => $queues,
            'total_pending' => $totalPending,
            'total_reserved' => $totalReserved,
            'total' => $totalPending + $totalReserved,
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

    public static function leastBusy(): ?self
    {
        $nodes = static::active()->worker()->get();

        if ($nodes->isEmpty()) {
            return null;
        }

        return $nodes->sortBy(fn(self $node) => $node->getJobCounts()['total'])->first();
    }

    public function resolveQueue(?int $sourceHeight = null): string
    {
        $needed = $this->determineWorkload($sourceHeight);
        $workload = $this->fallbackWorkload($needed);

        return "streams-node-{$this->id}-{$workload}";
    }

    private function determineWorkload(?int $sourceHeight): string
    {
        if (!$sourceHeight) {
            return 'light';
        }

        if ($sourceHeight >= 2160) {
            return 'heavy';
        }

        return 'medium';
    }

    private function fallbackWorkload(string $needed): string
    {
        $available = $this->getWorkloads();
        $priority = ['heavy', 'medium', 'light'];
        $startIndex = array_search($needed, $priority);

        for ($i = $startIndex; $i < count($priority); $i++) {
            if (in_array($priority[$i], $available)) {
                return $priority[$i];
            }
        }

        for ($i = $startIndex - 1; $i >= 0; $i--) {
            if (in_array($priority[$i], $available)) {
                return $priority[$i];
            }
        }

        return $needed;
    }
}
