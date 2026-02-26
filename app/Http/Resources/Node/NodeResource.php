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
            'ipAddress' => $this->ip_address,
            'type' => $this->type,
            'hostname' => $this->hostname,
            'isActive' => $this->is_active,
            'status' => $this->status,
            'uptime' => $this->uptime,
            'metrics' => $this->metrics,
            'sshKeyId' => $this->ssh_key_id,
            'lastSeenAt' => $this->updated_at?->diffForHumans(),
        ];
    }
}
