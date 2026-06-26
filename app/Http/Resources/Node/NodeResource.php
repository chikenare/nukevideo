<?php

namespace App\Http\Resources\Node;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NodeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'user' => $this->user,
            'ipAddress' => $this->ip_address,
            'type' => $this->type,
            'hostname' => $this->hostname,
            'isActive' => $this->is_active,
            'cdnMode' => $this->cdn_mode,
            'isStorageServer' => $this->is_storage_server,
            'storageEndpoint' => $this->storage_endpoint,
            'sshKeyId' => $this->ssh_key_id,
            'services' => $this->services ?? [],
            'log' => $this->log,
            'env' => $this->env,
            'lastSeenAt' => $this->updated_at?->diffForHumans(),
        ];
    }
}
