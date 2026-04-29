<?php

namespace App\Services;

use App\Http\Resources\Node\NodeResource;
use App\Models\Node;
use App\Settings\NodeSettings;
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

        foreach ($nodes as $node) {
            if (isset($metricsMap[$node->id])) {
                $node->setAttribute('metrics', $metricsMap[$node->id]);
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
        $sshKey = \App\Models\SshKey::findOrFail($data['ssh_key_id']);

        $this->validateSshConnection($data['ip_address'], $data['user'], $sshKey->private_key);

        return Node::create($data);
    }

    private function validateSshConnection(string $ip, string $user, string $privateKey): void
    {
        try {
            app(SSHService::class)->run($ip, $user, $privateKey, 'echo ok', 10);
        } catch (\Throwable $e) {
            Log::error('SSH connection validation failed', ['ip' => $ip, 'user' => $user, 'error' => $e->getMessage()]);
            throw new \Exception("SSH connection failed: {$e->getMessage()}");
        }
    }

    private function buildNodeEnv(Node $node): array
    {
        $env = $this->getEnvironmentVariables($node);

        $env[] = 'APP_ENV='.config('app.env');
        $env[] = 'APP_DEBUG='.(config('app.debug') ? 'true' : 'false');
        $env[] = 'APP_KEY='.config('app.key');
        $env[] = "NODE_ID={$node->id}";

        $scheme = app()->isLocal() ? 'http://' : 'https://';

        if ($node->type->value === 'proxy' && $node->hostname) {
            $env[] = "DOMAIN={$node->hostname}";
            $env[] = "VOD_BASE_URL=$scheme{$node->hostname}";

            if ($node->cdn_mode) {
                $env[] = 'CDN_MODE=true';
            }
        }

        return $env;
    }

    private function getEnvironmentVariables(Node $node): array
    {
        $settings = $this->parseEnvText(app(NodeSettings::class)->environment);
        $nodeOverrides = $this->parseEnvText($node->env ?? '');

        return array_values(array_merge($settings, $nodeOverrides));
    }

    private function parseEnvText(string $text): array
    {
        $vars = [];
        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_contains($line, '=')) {
                $key = explode('=', $line, 2)[0];
                $vars[$key] = $line;
            }
        }

        return $vars;
    }

    public static function workdir(Node $node): string
    {
        return "/home/{$node->user}/nukevideo-{$node->id}";
    }

    public function getPendingJobs(Node $node): array
    {
        return [
            'queue' => $node->resolveQueue(),
            'active_videos' => $node->activeVideoIds(),
            'available_workers' => $node->availableWorkers(),
        ];
    }

    public function runDeploy(Node $node, \Closure $onOutput): void
    {
        if ($node->type->value === 'proxy') {
            $onOutput("=== Deploying proxy containers ===\n");
            $this->deployProxy($node);
            $onOutput("Proxy containers deployed\n");

            if (app()->isProduction()) {
                $onOutput("=== Deploying Traefik ===\n");
                $this->deployTraefik($node);
                $onOutput("Traefik deployed\n");
            }
        } else {
            $onOutput("=== Deploying worker workers ===\n");
            $this->deployWorkerWorkers($node);
            $onOutput("Worker workers deployed\n");
        }

        $onOutput("=== Deploying Vector ===\n");
        $this->deployVector($node);
        $onOutput("Vector deployed\n");

        $onOutput("=== Deployment complete ===\n");
    }

    private function deployProxy(Node $node): void
    {
        $image = $this->resolveImage('proxy');

        $env = $this->buildNodeEnv($node);

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

        $name = "nukevideo_proxy_{$node->id}";

        $this->docker->deployContainer($node, $name, $image, [
            'env' => $env,
            'labels' => $labels,
            'network' => 'nukevideo_default',
        ]);
    }

    private function deployWorkerWorkers(Node $node): void
    {
        $workers = $node->workers ?? 1;

        for ($i = 0; $i < $workers; $i++) {
            $this->deployWorkerInstance($node, $i);
        }
    }

    private function deployWorkerInstance(Node $node, int $index): void
    {
        $image = $this->resolveImage('worker');
        $name = "nukevideo_worker_{$node->id}_{$index}";
        $workerTimeout = 21600;

        $env = $this->buildNodeEnv($node);
        $env[] = 'REDIS_QUEUE_RETRY_AFTER='.($workerTimeout + 300);
        $queue = $node->resolveQueue();

        if ($node->has_gpu) {
            $env[] = 'NVIDIA_DRIVER_CAPABILITIES=all';
        }

        $containerOptions = [
            'env' => $env,
            'labels' => ['vector.enable=true'],
            'volumes' => ['nukevideo_tmp:/tmp'],
            'command' => "php /var/www/html/artisan queue:work --queue={$queue} --timeout={$workerTimeout}",
        ];

        if ($node->has_gpu) {
            $containerOptions['gpus'] = 'all';
        }

        $this->docker->deployContainer($node, $name, $image, $containerOptions);
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

        $env = $this->buildNodeEnv($node);

        $volumes = ['/var/run/docker.sock:/var/run/docker.sock:ro'];

        $workdir = self::workdir($node);
        $vectorYaml = file_get_contents(base_path('vector/vector.yaml'));
        $this->ssh($node, "cat > {$workdir}/config/vector.yaml << 'VECTOREOF'\n{$vectorYaml}\nVECTOREOF");
        $volumes[] = "{$workdir}/config/vector.yaml:/etc/vector/vector.yaml:ro";

        $this->docker->deployContainer($node, $name, $image, [
            'env' => $env,
            'volumes' => $volumes,
        ]);
    }

    public function runSetup(Node $node, \Closure $onOutput): void
    {
        $script = file_get_contents(base_path('scripts/setup-node.sh'));
        $workdir = self::workdir($node);

        $args = "--workdir={$workdir}";
        if ($node->has_gpu) {
            $args .= ' --gpu';
        }

        $log = '';
        $collectOutput = function ($output) use ($onOutput, &$log) {
            $log .= $output;
            $onOutput($output);
        };

        $this->ssh($node, "bash -s -- {$args}", 180, $script, $collectOutput);

    }

    public function runValidation(Node $node): array
    {
        $checks = [
            ['key' => 'docker', 'label' => 'Docker'],
            ['key' => 'network', 'label' => 'Docker Network'],
            ['key' => 'containers', 'label' => 'Containers'],
            ['key' => 'disk', 'label' => 'Disk Space'],
        ];

        if ($node->has_gpu) {
            $checks[] = ['key' => 'gpu', 'label' => 'GPU'];
        }

        $results = [];

        foreach ($checks as $check) {
            try {
                $output = match ($check['key']) {
                    'docker' => $this->ssh($node, 'docker --version && docker info --format "Server: {{.ServerVersion}}"', 15),
                    'network' => $this->ssh($node, 'docker network inspect nukevideo_default --format "{{.Name}} ({{.Driver}})"', 15),
                    'containers' => $this->ssh($node, 'docker ps --filter name=nukevideo_ --format "{{.Names}}\t{{.Status}}"', 15),
                    'disk' => $this->ssh($node, 'df -h / | tail -1', 15),
                    'gpu' => $this->ssh($node, 'nvidia-smi --query-gpu=name,driver_version,memory.total --format=csv,noheader', 15),
                };

                $results[] = [
                    'key' => $check['key'],
                    'label' => $check['label'],
                    'status' => 'ok',
                    'output' => trim($output),
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'key' => $check['key'],
                    'label' => $check['label'],
                    'status' => 'error',
                    'output' => trim($e->getMessage()),
                ];
            }
        }

        return $results;
    }

    private function resolveImage(string $type): string
    {
        return "chikenare/nukevideo-{$type}:".config('app.version');
    }

    private function ssh(Node $node, string $command, int $timeout = 30, ?string $input = null, ?\Closure $onOutput = null): string
    {
        $sshService = app(SSHService::class);

        return $sshService->run(
            ip: $node->ip_address,
            user: $node->user,
            privateKey: $node->sshKey->private_key,
            command: $command,
            timeout: $timeout,
            input: $input,
            onOutput: $onOutput,
        );
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
