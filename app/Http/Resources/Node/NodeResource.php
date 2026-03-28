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
            'user' => $this->user,
            'ipAddress' => $this->ip_address,
            'type' => $this->type,
            'instances' => $this->instances,
            'hostname' => $this->hostname,
            'isActive' => $this->is_active,
            'metrics' => $this->metrics,
            'sshKeyId' => $this->ssh_key_id,
            'services' => $this->services ?? [],
            'log' => $this->log,
            'lastSeenAt' => $this->updated_at?->diffForHumans(),
        ];
    }
}
