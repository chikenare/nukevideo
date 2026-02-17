<?php

namespace App\Http\Resources\Node;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NodeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'host' => $this->host,
            'isActive' => $this->is_active,
            'maxWorkers' => $this->max_workers,
            'currentLoad' => $this->getCurrentLoad(),
            'availableCapacity' => $this->max_workers - $this->getCurrentLoad(),
            'location' => $this->location,
            'lastSeenAt' => $this->last_seen_at?->diffForHumans(),
        ];
    }
}
