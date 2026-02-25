<?php

namespace App\Models;

use App\Enums\NodeType;
use Illuminate\Database\Eloquent\Model;
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
}
