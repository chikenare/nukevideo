<?php

namespace App\Models;

use App\Enums\NodeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Node extends Model
{
    protected $fillable = [
        'name',
        'ip_address',
        'container_id',
        'type',
        'hostname',
        'is_active',
        'status',
        'uptime',
        'metrics',
        'swarm_node_id',
        'ssh_key_id',
        'log',
    ];

    protected function casts()
    {
        return [
            'type' => NodeType::class,
            'is_active' => 'boolean',
            'metrics' => 'array',
        ];
    }

    /**
     * Get all videos processed by this node
     */
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
}
