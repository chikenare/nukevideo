<?php

namespace App\Services;

use App\Data\NodeData;
use App\Data\SelfHostedConfigData;
use App\Models\Node;
use App\Settings\CdnSettings;
use App\Settings\NodeSettings;

class NodeService
{
    public function index(): array
    {
        return [
            'data' => [
                'nodes' => Node::all()->map(fn ($n) => NodeData::fromModel($n))->all(),
            ],
        ];
    }

    public function createNode(array $data): Node
    {
        return Node::create($data);
    }

    private const DOCKER_RUN_FLAGS = ['DOCKER_CPUSET_CPUS', 'DOCKER_MEMORY'];

    // Keeps the worker's idle Redis connection alive through ISP CGNAT during long ffmpeg
    // encodes; without outgoing traffic the NAT mapping is dropped and the next command read-errors.
    private const WORKER_SYSCTLS = [
        'net.ipv4.tcp_keepalive_time=60',
        'net.ipv4.tcp_keepalive_intvl=10',
        'net.ipv4.tcp_keepalive_probes=6',
    ];

    private const PROPAGATED_FROM_HOST = [
        'DB_CONNECTION',
        'DB_HOST',
        'DB_PORT',
        'DB_DATABASE',
        'DB_USERNAME',
        'DB_PASSWORD',

        'REDIS_HOST',
        'REDIS_PORT',
        'REDIS_PASSWORD',

        'AWS_ACCESS_KEY_ID',
        'AWS_SECRET_ACCESS_KEY',
        'AWS_DEFAULT_REGION',
        'AWS_BUCKET',
        'AWS_ENDPOINT',
        'AWS_USE_PATH_STYLE_ENDPOINT',

        'CLICKHOUSE_HOST',
        'CLICKHOUSE_PORT',
        'CLICKHOUSE_DATABASE',
        'CLICKHOUSE_USER',
        'CLICKHOUSE_PASSWORD',
        'CLICKHOUSE_ENDPOINT',

        'WEBHOOK_SECRET',
        'INTERNAL_API_SECRET',

        'SENTRY_LARAVEL_DSN',
        'SENTRY_TRACES_SAMPLE_RATE',
    ];

    public function getEnvironmentVariables(Node $node): array
    {
        $timeout = 600;

        $retryAfter = 1850;

        $scheme = app()->isLocal() ? 'http://' : 'https://';
        $endpoint = Node::where('is_storage_server', true)->value('storage_endpoint');

        $base = [
            'APP_ENV' => 'APP_ENV='.config('app.env'),
            'APP_DEBUG' => 'APP_DEBUG='.(config('app.debug') ? 'true' : 'false'),
            'APP_KEY' => 'APP_KEY='.config('app.key'),
            'APP_URL' => 'APP_URL='.config('app.url'),
            'API_UPSTREAM_HOST' => 'API_UPSTREAM_HOST='.parse_url(config('app.url'), PHP_URL_HOST),
            'NODE_ID' => "NODE_ID={$node->id}",
            'NODE_TYPE' => "NODE_TYPE={$node->type->value}",
            'VIDEO_WORKER_TIMEOUT' => "VIDEO_WORKER_TIMEOUT={$timeout}",
            'REDIS_QUEUE_RETRY_AFTER' => 'REDIS_QUEUE_RETRY_AFTER='.$retryAfter,
            'DOMAIN' => "DOMAIN={$node->hostname}",
            'VOD_BASE_URL' => "VOD_BASE_URL={$scheme}{$node->hostname}",
            'INTERNAL_API_URL' => 'INTERNAL_API_URL='.config('nuke.internal.url'),
        ];

        if ($endpoint) {
            $base['CHUNKS_S3_ENDPOINT'] = "CHUNKS_S3_ENDPOINT={$endpoint}";
        }

        foreach (self::PROPAGATED_FROM_HOST as $key) {
            $value = env($key);
            if ($value !== null && $value !== false) {
                $base[$key] = "{$key}=".(is_bool($value) ? ($value ? 'true' : 'false') : $value);
            }
        }

        // VOD/edge env now lives in CdnSettings (UI-editable), no longer host env. The nginx
        // container on proxy nodes still consumes these names; empty values are skipped so its
        // entrypoint defaults apply.
        $cdn = SelfHostedConfigData::from(app(CdnSettings::class)->providers['self_hosted'] ?? []);
        $cdnEnv = [
            'VOD_TOKEN_SECRET' => $cdn->tokenSecret,
            'SECURE_TOKEN_EXPIRES_TIME' => $cdn->secureTokenExpires,
            'SECURE_TOKEN_QUERY_EXPIRES_TIME' => $cdn->secureTokenQueryExpires,
            'VOD_CACHE_MAX_SIZE' => $cdn->cacheMaxSize,
            'VOD_CACHE_INACTIVE' => $cdn->cacheInactive,
        ];
        foreach ($cdnEnv as $key => $value) {
            if ($value !== '') {
                $base[$key] = "{$key}={$value}";
            }
        }

        $settings = $this->parseEnvText(app(NodeSettings::class)->environment);
        $nodeOverrides = $this->parseEnvText($node->env ?? '');

        return array_values(array_filter(
            array_merge($base, $settings, $nodeOverrides),
            fn ($v) => ! in_array(explode('=', $v, 2)[0], self::DOCKER_RUN_FLAGS)
        ));
    }

    private function extractDockerFlags(Node $node): array
    {
        $flags = [];
        foreach ($this->parseEnvText($node->env ?? '') as $key => $line) {
            if (in_array($key, self::DOCKER_RUN_FLAGS)) {
                $flags[$key] = explode('=', $line, 2)[1] ?? '';
            }
        }

        return $flags;
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
        return "/home/{$node->user}/nukevideo/node-{$node->id}";
    }

    public function runFullDeploy(Node $node, \Closure $onOutput): void
    {
        $script = $this->buildDeployScript($node);
        $this->ssh($node, 'bash -s', 300, $script, $onOutput);
    }

    public function buildDeployScript(Node $node): string
    {
        $workdir = self::workdir($node);
        $vectorImage = 'timberio/vector:0.56.0-alpine';
        $nodeType = $node->type->value;
        $nodeId = $node->id;
        $nodeName = $node->name;

        $nodeSection = match ($nodeType) {
            'worker' => $this->workerScript($node),
            'proxy' => $this->proxyScript($node),
            default => throw new \RuntimeException("Unknown node type: {$nodeType}"),
        };

        $vectorRunArgs = $this->buildDockerRunArgs('nukevideo_vector', $vectorImage, [
            'env' => $this->getEnvironmentVariables($node),
            'volumes' => [
                '/var/run/docker.sock:/var/run/docker.sock:ro',
                "{$workdir}/config/vector.yaml:/etc/vector/vector.yaml:ro",
            ],
        ]);

        $vectorYaml = file_get_contents(base_path('vector/vector.yaml'));

        return <<<BASH
        #!/bin/bash
        set -e

        # Nukevideo Node Deployment — {$nodeName} ({$nodeType}, ID: {$nodeId})

        WORKDIR="{$workdir}"
        SUDO=""
        [ "\$(id -u)" -ne 0 ] && SUDO="sudo"

        pull_image() {
            docker pull "\$1" 2>/dev/null || docker image inspect "\$1" &>/dev/null \
                || { echo "Image \$1 not found locally or in registry"; exit 1; }
        }

        # ---- 1. Docker ----
        echo "=== Installing Docker ==="
        if ! command -v docker &>/dev/null; then
            curl -fsSL https://get.docker.com | \$SUDO sh
            \$SUDO systemctl enable --now docker
            echo "Docker installed"
        else
            \$SUDO systemctl enable --now docker 2>/dev/null || true
            echo "Docker already installed: \$(docker --version)"
        fi
        \$SUDO usermod -aG docker "\$(id -un)" 2>/dev/null || true

        # ---- 2. Network & workdir ----
        \$SUDO docker network create nukevideo_default 2>/dev/null && echo "Network created" || echo "Network already exists"
        mkdir -p "\$WORKDIR/config" "\$WORKDIR/data" "\$WORKDIR/certs"

        # ---- 3. Vector config ----
        echo "=== Writing Vector config ==="
        cat > "\$WORKDIR/config/vector.yaml" << '__VECTOR_EOF__'
        {$vectorYaml}
        __VECTOR_EOF__

        # ---- 4-5. Node-specific: pull + deploy ----
        {$nodeSection}

        # ---- 6. Vector ----
        echo "=== Deploying Vector ==="
        pull_image {$vectorImage}
        docker rm -f nukevideo_vector 2>/dev/null || true
        docker run -d {$vectorRunArgs}

        echo ""
        echo "=== Deployment complete — node {$nodeId} is running ==="
        BASH;
    }

    private function workerScript(Node $node): string
    {
        if (! Node::where('is_storage_server', true)->whereNotNull('storage_endpoint')->exists()) {
            throw new \RuntimeException('No storage server configured. Flag one worker as the storage server (with an endpoint) before deploying.');
        }

        $image = $this->resolveImage('api');
        $name = "nukevideo_worker_{$node->id}";

        $dockerFlags = $this->extractDockerFlags($node);

        $runArgs = $this->buildDockerRunArgs($name, $image, [
            'env' => $this->getEnvironmentVariables($node),
            'labels' => ['vector.enable=true'],
            'sysctls' => self::WORKER_SYSCTLS,
            'command' => 'php /var/www/html/artisan horizon',
            'healthcheck' => 'healthcheck-horizon',
            'cpuset' => $dockerFlags['DOCKER_CPUSET_CPUS'] ?? null,
            'memory' => $dockerFlags['DOCKER_MEMORY'] ?? null,
        ]);

        $chunkStore = $node->is_storage_server ? $this->chunkStoreScript($node) : '';

        return <<<BASH
        echo "=== Pulling worker image ==="
        pull_image {$image}

        echo "=== Deploying worker ==="
        docker rm -f {$name} 2>/dev/null || true
        docker run -d {$runArgs}

        {$chunkStore}
        BASH;
    }

    private function proxyScript(Node $node): string
    {
        $image = $this->resolveImage('proxy');
        $name = "nukevideo_proxy_{$node->id}";

        $labels = ['vector.enable=true'];

        $isProduction = ! app()->isLocal();

        if ($node->hostname) {
            $entrypoint = $isProduction ? 'websecure' : 'web';
            $labels[] = 'traefik.enable=true';
            $labels[] = "traefik.http.routers.proxy.rule=Host(`{$node->hostname}`)";
            $labels[] = "traefik.http.routers.proxy.entrypoints={$entrypoint}";
            $labels[] = 'traefik.http.services.proxy.loadbalancer.server.port=80';
            if ($isProduction) {
                $labels[] = 'traefik.http.routers.proxy.tls.certresolver=le';
            }
        }

        $runArgs = $this->buildDockerRunArgs($name, $image, [
            'env' => $this->getEnvironmentVariables($node),
            'labels' => $labels,
            'network' => 'nukevideo_default',
        ]);

        $traefik = $isProduction ? $this->traefikScript() : '';

        return <<<BASH
        echo "=== Pulling proxy image ==="
        pull_image {$image}

        echo "=== Deploying proxy ==="
        docker rm -f {$name} 2>/dev/null || true
        docker run -d {$runArgs}

        {$traefik}
        BASH;
    }

    private function traefikScript(): string
    {
        $runArgs = $this->buildDockerRunArgs('nukevideo_traefik', 'traefik:v3.6', [
            'ports' => ['80:80', '443:443', '8080:8080'],
            'volumes' => [
                '/var/run/docker.sock:/var/run/docker.sock:ro',
                'traefik_certs:/certs',
            ],
            'command' => '--api.insecure=true --providers.docker=true --providers.docker.exposedbydefault=false'
                .' --entrypoints.web.address=:80 --entrypoints.websecure.address=:443'
                .' --certificatesresolvers.le.acme.httpchallenge.entrypoint=web'
                .' --certificatesresolvers.le.acme.storage=/certs/acme.json',
            'network' => 'nukevideo_default',
        ]);

        return <<<BASH
        echo "=== Deploying Traefik ==="
        docker rm -f nukevideo_traefik 2>/dev/null || true
        docker run -d {$runArgs}
        echo "Traefik deployed"
        BASH;
    }

    private function chunkStoreScript(Node $node): string
    {
        $disk = config('filesystems.disks.chunks');
        $port = (int) (parse_url((string) $node->storage_endpoint, PHP_URL_PORT) ?: 9000);
        $storeName = "nukevideo_storage_{$node->id}";
        $mcHostArg = escapeshellarg(sprintf('MC_HOST_rfs=http://%s:%s@127.0.0.1:%d', $disk['key'], $disk['secret'], $port));
        $mcCmd = escapeshellarg("mc mb --ignore-existing rfs/{$disk['bucket']}");

        $runArgs = $this->buildDockerRunArgs($storeName, 'rustfs/rustfs:latest', [
            'env' => [
                'RUSTFS_ACCESS_KEY='.$disk['key'],
                'RUSTFS_SECRET_KEY='.$disk['secret'],
                'RUSTFS_ADDRESS=:9000',
                'RUSTFS_CONSOLE_ENABLE=false',
            ],
            'labels' => ['vector.enable=true'],
            'ports' => ["{$port}:9000"],
            'volumes' => ['nukevideo_chunks:/data'],
            'command' => '/data',
        ]);

        return <<<BASH
        echo "=== Deploying chunk store ==="
        docker rm -f {$storeName} 2>/dev/null || true
        docker run -d {$runArgs}
        echo "Waiting for chunk store..."
        n=0
        until docker run --rm --network host -e {$mcHostArg} --entrypoint sh minio/mc -c {$mcCmd}; do
          n=\$((n+1)); [ \$n -ge 30 ] && echo "Chunk store failed to start" && exit 1; sleep 2
        done
        echo "Chunk store ready"
        BASH;
    }

    public function runValidation(Node $node): array
    {
        $checks = [
            ['key' => 'docker', 'label' => 'Docker'],
            ['key' => 'network', 'label' => 'Docker Network'],
            ['key' => 'containers', 'label' => 'Containers'],
            ['key' => 'disk', 'label' => 'Disk Space'],
        ];

        $results = [];

        foreach ($checks as $check) {
            try {
                $output = match ($check['key']) {
                    'docker' => $this->ssh($node, 'docker --version && docker info --format "Server: {{.ServerVersion}}"', 15),
                    'network' => $this->ssh($node, 'docker network inspect nukevideo_default --format "{{.Name}} ({{.Driver}})"', 15),
                    'containers' => $this->ssh($node, 'docker ps --filter name=nukevideo_ --format "{{.Names}}\t{{.Status}}"', 15),
                    'disk' => $this->ssh($node, 'df -h / | tail -1', 15),
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

    private function buildDockerRunArgs(string $name, string $image, array $options): string
    {
        $cmd = "--name {$name} --restart unless-stopped";

        foreach ($options['env'] ?? [] as $env) {
            $cmd .= ' -e '.escapeshellarg($env);
        }
        foreach ($options['volumes'] ?? [] as $volume) {
            $cmd .= ' -v '.escapeshellarg($volume);
        }
        foreach ($options['ports'] ?? [] as $port) {
            $cmd .= " -p {$port}";
        }
        foreach ($options['labels'] ?? [] as $label) {
            $cmd .= ' -l '.escapeshellarg($label);
        }
        foreach ($options['sysctls'] ?? [] as $sysctl) {
            $cmd .= ' --sysctl '.escapeshellarg($sysctl);
        }

        if (! empty($options['cpuset'])) {
            $cmd .= ' --cpuset-cpus '.escapeshellarg($options['cpuset']);
        }
        if (! empty($options['memory'])) {
            $cmd .= ' --memory '.escapeshellarg($options['memory']);
        }

        if (isset($options['network'])) {
            $cmd .= ' --network '.escapeshellarg($options['network']);
        }

        if (isset($options['healthcheck'])) {
            $cmd .= ' --health-cmd '.escapeshellarg($options['healthcheck']);
        }

        $cmd .= isset($options['command']) ? " {$image} {$options['command']}" : " {$image}";

        return $cmd;
    }
}
