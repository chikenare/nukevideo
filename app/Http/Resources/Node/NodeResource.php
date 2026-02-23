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
            'baseUrl' => $this->base_url,
            'isActive' => $this->is_active,
            'status' => $this->status,
            'location' => $this->location,
            'uptime' => $this->uptime,
            'metrics' => $this->metrics,
            'lastSeenAt' => $this->updated_at?->diffForHumans(),
        ];
    }
}
