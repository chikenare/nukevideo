<?php

namespace App\Data;

use App\Enums\NodeType;
use App\Models\Node;
use Spatie\LaravelData\Data;

class NodeData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $user,
        public string $ipAddress,
        public NodeType $type,
        public ?string $hostname,
        public bool $isActive,
        public bool $isStorageServer,
        public ?string $storageEndpoint,
        public ?int $sshKeyId,
        /** @var ServiceStatusData[] */
        public array $services,
        public ?string $log,
        public ?string $env,
        public ?string $lastSeenAt,
    ) {}

    public static function fromModel(Node $node): self
    {
        return new self(
            id: $node->id,
            name: $node->name,
            user: $node->user,
            ipAddress: $node->ip_address,
            type: $node->type,
            hostname: $node->hostname,
            isActive: $node->is_active,
            isStorageServer: (bool) $node->is_storage_server,
            storageEndpoint: $node->storage_endpoint,
            sshKeyId: $node->ssh_key_id,
            services: ServiceStatusData::collect($node->services ?? []),
            log: $node->log,
            env: $node->env,
            lastSeenAt: $node->updated_at?->diffForHumans(),
        );
    }
}
