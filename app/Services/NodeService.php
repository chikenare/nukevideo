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

        $swarmNodeId = $this->docker->getSwarmNodeId($ip);

        $node->update([
            'status' => 'provisioned',
            'swarm_node_id' => $swarmNodeId,
        ]);
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
        $node->update(['status' => 'deploying']);
        broadcast(new NodeOutput($node->id, "Deploying {$node->type->value} service..."));

        $stackName = 'nukevideo';
        $image = "chikenare/nukevideo-{$node->type->value}:latest";
        $serviceName = "{$stackName}_{$node->type->value}";

        $env = array_filter(explode("\n", trim($this->buildNodeEnv($node))));

        $spec = [
            'TaskTemplate' => [
                'ContainerSpec' => [
                    'Image' => $image,
                    'Env' => array_values($env),
                ],
            ],
            'Mode' => [
                'Replicated' => [
                    'Replicas' => $node->replicas ?? 2,
                ],
            ],
            'UpdateConfig' => [
                'Parallelism' => 1,
                'Delay' => 10000000000,
                'Order' => 'start-first',
                'FailureAction' => 'rollback',
            ],
            'RollbackConfig' => [
                'Parallelism' => 1,
                'Order' => 'stop-first',
            ],
        ];

        if ($node->type->value === 'proxy' && $node->hostname) {
            $isProduction = app()->environment('production');
            $entrypoint = $isProduction ? 'websecure' : 'web';

            $spec['Labels'] = [
                'traefik.enable' => 'true',
                'traefik.http.routers.proxy.rule' => "Host(`{$node->hostname}`)",
                'traefik.http.routers.proxy.entrypoints' => $entrypoint,
                'traefik.http.services.proxy.loadbalancer.server.port' => '80',
            ];

            if ($isProduction) {
                $spec['Labels']['traefik.http.routers.proxy.tls.certresolver'] = 'le';
            }
        }

        $this->docker->deployService($serviceName, $spec);

        broadcast(new NodeOutput($node->id, "Service {$serviceName} deployed successfully"));
        $node->update(['status' => 'running']);
    }
}
