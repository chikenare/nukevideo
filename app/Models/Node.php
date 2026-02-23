<?php

namespace App\Models;

use App\Enums\NodeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Node extends Model
{
    protected $fillable = [
        'name',
        'container_id',
        'type',
        'base_url',
        'is_active',
        'status',
        'uptime',
        'metrics',
        'location',
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

    /**
     * Get the current load (pending + running jobs) for this node
     */
    public function getCurrentLoad(): int
    {
        return $this->videos()
            ->whereIn('status', ['pending', 'running'])
            ->count();
    }

    public function markAsSeen(): void
    {
        $this->touch();
    }
}
