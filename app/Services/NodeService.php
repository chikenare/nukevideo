<?php

namespace App\Services;

use App\Http\Resources\Node\NodeResource;
use App\Models\Node;
use Illuminate\Support\Facades\Log;

class NodeService
{
    public function __construct(
        private DockerService $docker,
    ) {}

    public function index(): array
    {
        $nodes = Node::all();

        try {
            $metricsMap = $this->metrics();
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch node metrics: '.$e->getMessage());
            $metricsMap = [];
        }

        $fallbackMetrics = count($metricsMap) === 1 ? reset($metricsMap) : null;

        foreach ($nodes as $node) {
            $m = $metricsMap[$node->id] ?? $fallbackMetrics;
            if ($m) {
                $node->setAttribute('metrics', $m);
            }
        }

        return [
            'data' => [
                'nodes' => NodeResource::collection($nodes),
            ],
        ];
    }

    public function createNode(array $data): Node
    {
        return Node::create($data);
    }

    private function buildNodeEnv(Node $node, ?string $workload = null, ?int $instanceIndex = null): array
    {
        $envVars = [
            'NODE_ID' => $node->id,
            'NODE_TYPE' => $node->type->value,
        ];

        if ($instanceIndex !== null) {
            $envVars['INSTANCE_INDEX'] = $instanceIndex;
        }

        if ($workload) {
            $envVars['WORKLOAD'] = $workload;
        }

        if ($node->type->value === 'proxy' && $node->hostname) {
            $envVars['DOMAIN'] = $node->hostname;
        }

        $baseVars = $this->parseEnvFromExample();
        $envVars = array_merge($baseVars, $envVars);

        return array_map(fn ($k, $v) => "{$k}={$v}", array_keys($envVars), $envVars);
    }

    private function buildProxyEnv(): array
    {
        $content = file_get_contents(base_path('vod/entrypoint.sh'));
        preg_match("/FILTER_VARS='([^']+)'/", $content, $matches);

        $keys = array_map(
            fn (string $var) => ltrim($var, '$'),
            preg_split('/\s+/', trim($matches[1]))
        );

        $env = [];
        foreach ($keys as $key) {
            $value = env($key);
            if ($value !== null) {
                $env[] = "{$key}={$value}";
            }
        }

        return $env;
    }

    private function parseEnvFromExample(): array
    {
        $examplePath = base_path('.env.example');
        $lines = file($examplePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $env = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, '#')) {
                continue;
            }

            $key = explode('=', $line, 2)[0] ?? null;

            $value = env($key);
            if ($key && $value !== null) {
                $env[$key] = match (true) {
                    $value === true => 'true',
                    $value === false => 'false',
                    default => (string) $value,
                };
            }
        }

        return $env;
    }

    public static function workdir(Node $node): string
    {
        return "/home/{$node->user}/nukevideo/nodes/{$node->id}";
    }

    public function getPendingJobs(Node $node): array
    {
        return $node->getJobCounts();
    }

    public function getDeploySteps(Node $node): array
    {
        $steps = [
            ['key' => 'setup', 'label' => 'Setup workdir'],
        ];

        if ($node->type->value === 'proxy') {
            $steps[] = ['key' => 'proxy', 'label' => 'Deploy proxy containers'];
            if (app()->isProduction()) {
                $steps[] = ['key' => 'traefik', 'label' => 'Deploy Traefik'];
            }
        } else {
            $steps[] = ['key' => 'workers', 'label' => 'Deploy worker instances'];
        }

        $steps[] = ['key' => 'vector', 'label' => 'Deploy Vector'];

        return $steps;
    }

    public function deployStep(Node $node, string $step): void
    {
        match ($step) {
            'setup' => $this->setupWorkdir($node),
            'proxy' => $this->deployProxy($node),
            'traefik' => $this->deployTraefik($node),
            'workers' => $this->deployWorkerInstances($node),
            'vector' => $this->deployVector($node),
            default => throw new \InvalidArgumentException("Unknown deploy step: {$step}"),
        };
    }

    private function setupWorkdir(Node $node): void
    {
        $workdir = self::workdir($node);
        $this->ssh($node, "mkdir -p {$workdir}/config {$workdir}/data {$workdir}/certs");
    }

    private function deployProxy(Node $node): void
    {
        $instances = $node->instances ?? [];
        $replicas = count($instances);

        if ($replicas === 0) {
            return;
        }

        $first = $instances[0];
        $image = 'chikenare/nukevideo-proxy:latest';

        $env = $this->buildProxyEnv();

        $labels = [
            'vector.enable=true',
        ];

        if ($node->hostname) {
            $isProduction = app()->environment('production');
            $entrypoint = $isProduction ? 'websecure' : 'web';

            $labels[] = 'traefik.enable=true';
            $labels[] = "traefik.http.routers.proxy.rule=Host(`{$node->hostname}`)";
            $labels[] = "traefik.http.routers.proxy.entrypoints={$entrypoint}";
            $labels[] = 'traefik.http.services.proxy.loadbalancer.server.port=80';

            if ($isProduction) {
                $labels[] = 'traefik.http.routers.proxy.tls.certresolver=le';
            }
        }

        // Deploy multiple proxy replicas as individual containers
        for ($i = 0; $i < $replicas; $i++) {
            $name = "nukevideo_proxy_{$node->id}_{$i}";

            $this->docker->deployContainer($node, $name, $image, [
                'env' => $env,
                'labels' => $labels,
                'network' => 'nukevideo_default',
                'cpus' => $this->nanoCpusToCpus($first['nano_cpus'] ?? null),
                'memory' => $this->bytesToMemoryString($first['memory_bytes'] ?? null),
            ]);
        }

        // Deploy Traefik for this proxy node (only in production)
        if (app()->isProduction()) {
            $this->deployTraefik($node);
        }
    }

    private function deployWorkerInstances(Node $node): void
    {
        $instances = $node->instances ?? [];

        foreach ($instances as $index => $instance) {
            $this->deployWorkerInstance($node, $instance, $index);
        }
    }

    private function deployWorkerInstance(Node $node, array $instance, int $index): void
    {
        $image = 'chikenare/nukevideo-worker:latest';
        $name = "nukevideo_worker_{$node->id}_{$index}";

        $workload = $instance['workload'] ?? null;
        $env = $this->buildNodeEnv($node, $workload, $index);
        $queue = "streams-node-{$node->id}-{$workload}";

        $this->docker->deployContainer($node, $name, $image, [
            'env' => $env,
            'labels' => ['vector.enable=true'],
            'volumes' => ['nukevideo_tmp:/tmp'],
            'cpus' => $this->nanoCpusToCpus($instance['nano_cpus'] ?? null),
            'memory' => $this->bytesToMemoryString($instance['memory_bytes'] ?? null),
            'command' => "php /var/www/html/artisan queue:work --queue={$queue} --timeout=3200",
        ]);
    }

    private function deployTraefik(Node $node): void
    {
        $image = 'traefik:v3.6';
        $name = 'nukevideo_traefik';

        $this->docker->deployContainer($node, $name, $image, [
            'ports' => ['80:80', '443:443', '8080:8080'],
            'volumes' => [
                '/var/run/docker.sock:/var/run/docker.sock:ro',
                'traefik_certs:/certs',
            ],
            'command' => implode(' ', [
                '--api.insecure=true',
                '--providers.docker=true',
                '--providers.docker.exposedbydefault=false',
                '--entrypoints.web.address=:80',
                '--entrypoints.websecure.address=:443',
                '--certificatesresolvers.le.acme.httpchallenge.entrypoint=web',
                '--certificatesresolvers.le.acme.storage=/certs/acme.json',
            ]),
            'network' => 'nukevideo_default',
        ]);
    }

    private function deployVector(Node $node): void
    {
        $image = 'timberio/vector:0.53.0-alpine';
        $name = 'nukevideo_vector';

        $env = [];

        if (app()->isProduction()) {
            $baseVars = $this->parseEnvFromExample();
            $keys = ['CLICKHOUSE_ENDPOINT', 'CLICKHOUSE_DATABASE', 'CLICKHOUSE_USER', 'CLICKHOUSE_PASSWORD'];
            foreach ($keys as $key) {
                if (isset($baseVars[$key])) {
                    $env[] = "{$key}={$baseVars[$key]}";
                }
            }
        } else {
            foreach (['CLICKHOUSE_ENDPOINT', 'CLICKHOUSE_DATABASE', 'CLICKHOUSE_USER', 'CLICKHOUSE_PASSWORD'] as $key) {
                $value = env($key);
                if ($value) {
                    $env[] = "{$key}={$value}";
                }
            }
        }

        $env[] = "NODE_ID={$node->id}";

        $volumes = ['/var/run/docker.sock:/var/run/docker.sock:ro'];

        $workdir = self::workdir($node);
        $vectorYaml = file_get_contents(base_path('vod/vector/vector.yaml'));
        $this->ssh($node, "cat > {$workdir}/config/vector.yaml << 'VECTOREOF'\n{$vectorYaml}\nVECTOREOF");
        $volumes[] = "{$workdir}/config/vector.yaml:/etc/vector/vector.yaml:ro";

        $this->docker->deployContainer($node, $name, $image, [
            'env' => $env,
            'volumes' => $volumes,
        ]);
    }

    private function ssh(Node $node, string $command): string
    {
        $sshService = app(SSHService::class);

        return $sshService->run(
            ip: $node->ip_address,
            user: $node->user,
            privateKey: $node->sshKey->private_key,
            command: $command,
            timeout: 30,
        );
    }

    private function nanoCpusToCpus(?int $nanoCpus): ?string
    {
        if (! $nanoCpus) {
            return null;
        }

        return (string) round($nanoCpus / 1_000_000_000, 2);
    }

    private function bytesToMemoryString(?int $bytes): ?string
    {
        if (! $bytes) {
            return null;
        }

        $gb = $bytes / (1024 ** 3);
        if ($gb >= 1 && fmod($gb, 1) === 0.0) {
            return ((int) $gb).'g';
        }

        $mb = (int) ($bytes / (1024 ** 2));

        return $mb.'m';
    }

    public function metrics(): array
    {
        $client = app(\ClickHouseDB\Client::class);

        $gauges = $client->select(<<<'SQL'
            SELECT
                node_id,
                metric,
                argMax(value, timestamp) AS value
            FROM host_metrics
            WHERE timestamp >= now() - INTERVAL 5 MINUTE
            GROUP BY node_id, metric
        SQL);

        $nodes = [];

        foreach ($gauges->rows() as $row) {
            $nodeId = $row['node_id'];
            $metric = $row['metric'];
            $value = $row['value'];

            $nodes[$nodeId][$metric] = $value;
        }

        $result = [];

        foreach ($nodes as $nodeId => $m) {
            $memTotal = $m['memory_total_bytes'] ?? 0;
            $memUsed = ($m['memory_total_bytes'] ?? 0) - ($m['memory_available_bytes'] ?? 0);

            $result[$nodeId] = [
                'node_id' => $nodeId,
                'cpu' => [
                    'load_1' => round($m['load1'] ?? 0, 2),
                    'load_5' => round($m['load5'] ?? 0, 2),
                    'load_15' => round($m['load15'] ?? 0, 2),
                ],
                'memory' => [
                    'total' => $memTotal,
                    'used' => $memUsed,
                    'percent' => $memTotal > 0 ? round($memUsed / $memTotal * 100, 1) : 0,
                ],
                'disk' => [
                    'read_bytes' => $m['disk_read_bytes_total'] ?? 0,
                    'written_bytes' => $m['disk_written_bytes_total'] ?? 0,
                ],
                'network' => [
                    'rx_bytes' => $m['network_receive_bytes_total'] ?? 0,
                    'tx_bytes' => $m['network_transmit_bytes_total'] ?? 0,
                ],
            ];
        }

        return $result;
    }
}
