<?php

namespace App\Services;

use App\Http\Resources\Node\NodeResource;
use App\Models\Node;

class NodeService
{
    public function __construct(
        private SSHService $ssh,
    ) {
    }

    public function deactivateNode(Node $node): void
    {
        $node->update(['is_active' => false]);
    }

    public function index(): array
    {
        $nodes = Node::all();

        return [
            'data' => [
                'nodes' => NodeResource::collection($nodes),
            ]
        ];
    }

    public function createNode(array $data): Node
    {
        return Node::create($data);
    }

    public function syncNodeMetrics(Node $node): Node
    {
        $metrics = $this->getNodeMetrics($node);

        $node->update([
            'status' => 'running',
            'is_active' => true,
            'metrics' => $metrics['metrics'],
            'uptime' => $metrics['uptime'],
        ]);

        return $node->fresh();
    }

    public function getNodeMetrics(Node $node): array
    {
        $ip = $node->ip_address;

        // $result = $this->ssh->run($ip, 'sh /var/www/html/scripts/node-metrics.sh');
        $result = $this->ssh->run($ip, 'sh /home/nodexd/node-metrics.sh');

        $json = json_decode($result, true);
        return $json;
    }
}
