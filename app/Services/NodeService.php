<?php

namespace App\Services;

use App\Events\NodeOutput;
use App\Http\Resources\Node\NodeResource;
use App\Models\Node;

class NodeService
{
    public function __construct(
        private SSHService $ssh,
        private DockerService $docker,
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
        $key = $node->sshKey->private_key;

        $result = $this->ssh->run($ip, $key, 'sh ~/var/www/html/scripts/node-metrics.sh');

        $json = json_decode($result, true);
        return $json;
    }

    public function provisionNode(Node $node): void
    {
        $ip = $node->ip_address;
        $key = $node->sshKey->private_key;

        $node->update(['status' => 'provisioning']);

        // Build .env: base from docker config + node-specific vars
        $env = $this->buildNodeEnv($node);

        // Create directory and write .env via SSH
        $this->ssh->run(
            ip: $ip,
            privateKey: $key,
            command: "mkdir -p /opt/nukevideo && cat > /opt/nukevideo/.env",
            input: $env,
            timeout: 30,
            onOutput: function (string $output) use ($node) {
                broadcast(new NodeOutput($node->id, $output));
            },
        );

        // Join the swarm
        $managerIp = $this->docker->getSwarmManagerIp();
        $joinToken = $this->docker->getSwarmJoinToken();

        $this->ssh->run(
            ip: $ip,
            privateKey: $key,
            command: "docker swarm leave --force 2>/dev/null; docker swarm join --token {$joinToken} {$managerIp}:2377",
            timeout: 30,
            onOutput: function (string $output) use ($node) {
                broadcast(new NodeOutput($node->id, $output));
            },
        );

        $node->update(['status' => 'provisioned']);
    }

    private function buildNodeEnv(Node $node): string
    {
        // Base env from docker config
        $baseEnv = $this->docker->getConfigContent('nukevideo_nodes_env');

        // Node-specific vars
        $nodeVars = [
            'NODE_TYPE' => $node->type->value,
            'REPLICAS' => $node->replicas ?? 2,
        ];

        if ($node->type->value === 'proxy' && $node->hostname) {
            $nodeVars['DOMAIN'] = $node->hostname;
        }

        $lines = [];
        foreach ($nodeVars as $k => $v) {
            $lines[] = "{$k}={$v}";
        }

        return trim($baseEnv) . "\n" . implode("\n", $lines) . "\n";
    }

    public function deploy(Node $node): void
    {
        $ip = $node->ip_address;
        $script = file_get_contents(base_path('scripts/deploy.sh'));
        $key = $node->sshKey->private_key;

        $this->ssh->run(
            ip: $ip,
            privateKey: $key,
            command: "bash -s",
            input: $script,
            timeout: 300,
            onOutput: function (string $output) use ($node) {
                broadcast(new NodeOutput($node->id, $output));
            },
        );
    }
}
