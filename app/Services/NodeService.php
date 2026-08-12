<?php

namespace App\Services;

use App\Console\Commands\IngestBunnyLogs;
use App\Data\NodeData;
use App\Data\SelfHostedConfigData;
use App\Enums\CdnDriver;
use App\Enums\NodeAccel;
use App\Enums\NodeType;
use App\Models\Node;
use App\Settings\CdnSettings;
use App\Settings\NodeSettings;
use App\Support\Cpu;

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

    // What every worker node runs with. Public because they are also the recovery window a video
    // gets when a worker dies mid-job: the queue re-delivers after RETRY_AFTER and the redelivered
    // job may run for WORKER_TIMEOUT, so {@see \App\Console\Commands\ReapStuckVideos} waits both
    // out before calling a video stuck. The API host's own env says nothing about either.
    public const WORKER_TIMEOUT = 600;

    public const QUEUE_RETRY_AFTER = 1850;

    // How long a redeploy waits for the old worker to finish its in-flight jobs before SIGKILL.
    // Horizon on SIGTERM stops taking work and finishes what it has; killing it early instead
    // strands those jobs as `reserved` in Redis for QUEUE_RETRY_AFTER (~31 min) with the new
    // container up and idle. One chunk pass (WORKER_TIMEOUT) plus slack covers what is running
    // almost always; a packaging job caught mid-run still pays the redelivery wait, by choice —
    // covering it too would hold every deploy for up to 30 minutes.
    public const WORKER_STOP_GRACE = self::WORKER_TIMEOUT + 60;

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
            'VIDEO_WORKER_TIMEOUT' => 'VIDEO_WORKER_TIMEOUT='.self::WORKER_TIMEOUT,
            'REDIS_QUEUE_RETRY_AFTER' => 'REDIS_QUEUE_RETRY_AFTER='.self::QUEUE_RETRY_AFTER,
            'DOMAIN' => "DOMAIN={$node->hostname}",
            'VOD_BASE_URL' => "VOD_BASE_URL={$scheme}{$node->hostname}",
            'INTERNAL_API_URL' => 'INTERNAL_API_URL='.config('nuke.internal.url'),
        ];

        if ($endpoint) {
            $base['CHUNKS_S3_ENDPOINT'] = "CHUNKS_S3_ENDPOINT={$endpoint}";
        }

        if ($node->accel) {
            $base['NODE_ACCEL'] = "NODE_ACCEL={$node->accel->value}";
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

    /**
     * Reads the same merged chain the environment does, node overriding settings. It used to look
     * only at the node's own `env`, so a `DOCKER_MEMORY` set once in the global node environment
     * was stripped out as a docker flag and then never recovered as one — the container ran with
     * no `--memory` and no variable either, and {@see Cpu} sized the encode pool for
     * the host's whole RAM. The two sides have to read from one place or the knob lies.
     */
    private function extractDockerFlags(Node $node): array
    {
        $merged = array_merge(
            $this->parseEnvText(app(NodeSettings::class)->environment),
            $this->parseEnvText($node->env ?? ''),
        );

        $flags = [];
        foreach ($merged as $key => $line) {
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
        return "/home/{$node->user}/nukevideo/node-{$node->uuid}";
    }

    public function runFullDeploy(Node $node, \Closure $onOutput, bool $drain = true): void
    {
        $script = $this->buildDeployScript($node);
        $grace = $drain ? self::WORKER_STOP_GRACE : 0;

        // SSH timeout covers the worst case: the drain window plus image pull and startup.
        // The old flat 300s cut the session mid-drain and left no container at all.
        $this->ssh($node, 'bash -s'.($drain ? '' : ' -- --no-drain'), $grace + 600, $script, $onOutput);
    }

    public function buildDeployScript(Node $node): string
    {
        $workdir = self::workdir($node);
        // The path embeds `$node->user`, which is validated as a POSIX login name — quoted here
        // as well so a value that ever slipped past validation can only produce a bad path, not
        // a second command. (Every other free-text value reaches the script through
        // buildDockerRunArgs, which escapes it already.)
        $workdirArg = escapeshellarg($workdir);
        $stopGrace = self::WORKER_STOP_GRACE;
        $nodeType = $node->type->value;
        $nodeId = $node->id;
        // The name is free text and lands on a `#` comment line, which a newline would end —
        // everything after it would run on the node as part of the script.
        $nodeName = preg_replace('/[^\w .\-]/u', '', $node->name);

        $nodeSection = match ($nodeType) {
            'worker' => $this->workerScript($node),
            'proxy' => $this->proxyScript($node),
            default => throw new \RuntimeException("Unknown node type: {$nodeType}"),
        };

        $vectorSection = $this->vectorScript($node, $workdir);

        return <<<BASH
        #!/bin/bash
        set -e

        # Nukevideo Node Deployment — {$nodeName} ({$nodeType}, ID: {$nodeId})

        WORKDIR={$workdirArg}
        SUDO=""
        [ "\$(id -u)" -ne 0 ] && SUDO="sudo"

        # Seconds the old worker gets to finish in-flight jobs before it is killed. The default
        # covers one full chunk pass; docker stop returns the moment Horizon exits, so an idle
        # worker drains in seconds either way. Override per run when the wait is not worth it
        # (killed jobs sit reserved in Redis for ~31 min before redelivery):
        #   curl ... | bash -s -- --no-drain
        #   curl ... | bash -s -- --drain=60
        DRAIN={$stopGrace}
        for arg in "\$@"; do
            case "\$arg" in
                --no-drain) DRAIN=0 ;;
                --drain=*) DRAIN="\${arg#--drain=}" ;;
            esac
        done

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

        # ---- 3-4. Node-specific: image + deploy ----
        {$nodeSection}

        # ---- 5. Vector ----
        {$vectorSection}

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
        $name = $node->serviceContainerName();
        $stopGrace = self::WORKER_STOP_GRACE;

        $dockerFlags = $this->extractDockerFlags($node);

        $runArgs = $this->buildDockerRunArgs($name, $image, [
            'env' => $this->getEnvironmentVariables($node),
            'labels' => ['vector.enable=true'],
            'sysctls' => self::WORKER_SYSCTLS,
            // So a plain `docker stop` (host reboot included) also drains instead of killing at 10s.
            'stop_timeout' => $stopGrace,
            'command' => 'php /var/www/html/artisan horizon',
            'healthcheck' => 'healthcheck-horizon',
            'cpuset' => $dockerFlags['DOCKER_CPUSET_CPUS'] ?? null,
            'memory' => $dockerFlags['DOCKER_MEMORY'] ?? null,
            // $RENDER_GID is resolved by the deploy script below, on the node itself.
            'devices' => $node->accel === NodeAccel::INTEL ? ['/dev/dri:/dev/dri'] : [],
            'group_add' => $node->accel === NodeAccel::INTEL ? '"${RENDER_GID:-44}"' : null,
            'gpus' => $node->accel === NodeAccel::NVIDIA,
        ]);

        $chunkStore = $node->is_storage_server ? $this->chunkStoreScript($node) : '';
        $gpuSetup = $this->gpuSetupScript($node);

        return <<<BASH
        {$gpuSetup}

        echo "=== Worker image ==="
        {$this->imageStep($image, 'api-prod')}

        echo "=== Deploying worker ==="
        # Drain before replacing: give Horizon time to finish in-flight encodes, or they sit
        # reserved in Redis for ~31 minutes with the new container idle. Returns as soon as
        # Horizon exits — the cap only bites with a job mid-flight. `-t`, not `--time`/`--timeout`:
        # the long forms flipped between docker CLI generations, the short one never did.
        if [ "\$DRAIN" -gt 0 ] && docker inspect {$name} &>/dev/null; then
            echo "Draining running jobs (up to \${DRAIN}s)..."
            docker stop -t "\$DRAIN" {$name} || true
        fi
        docker rm -f {$name} 2>/dev/null || true
        docker run -d {$runArgs}

        {$chunkStore}
        BASH;
    }

    /**
     * Host-side GPU prep, ran before the worker container starts. Intel only needs the render
     * group's GID (the container user joins it to open /dev/dri). NVIDIA needs the container
     * toolkit so `--gpus all` works; the kernel driver itself must already be on the host.
     */
    private function gpuSetupScript(Node $node): string
    {
        return match ($node->accel) {
            NodeAccel::INTEL => <<<'BASH'
            echo "=== Intel GPU ==="
            [ -e /dev/dri/renderD128 ] || { echo "No /dev/dri render node found — is the GPU driver loaded?"; exit 1; }
            RENDER_GID=$(getent group render | cut -d: -f3)
            echo "Render node present, render GID: ${RENDER_GID:-44 (fallback)}"
            BASH,
            NodeAccel::NVIDIA => <<<'BASH'
            echo "=== NVIDIA GPU ==="
            command -v nvidia-smi &>/dev/null || { echo "nvidia-smi not found — install the NVIDIA driver first"; exit 1; }
            nvidia-smi --query-gpu=name --format=csv,noheader
            if ! command -v nvidia-ctk &>/dev/null; then
                echo "Installing NVIDIA container toolkit"
                curl -fsSL https://nvidia.github.io/libnvidia-container/gpgkey | $SUDO gpg --dearmor --yes -o /usr/share/keyrings/nvidia-container-toolkit-keyring.gpg
                curl -fsSL https://nvidia.github.io/libnvidia-container/stable/deb/nvidia-container-toolkit.list \
                    | sed 's#deb https://#deb [signed-by=/usr/share/keyrings/nvidia-container-toolkit-keyring.gpg] https://#g' \
                    | $SUDO tee /etc/apt/sources.list.d/nvidia-container-toolkit.list > /dev/null
                $SUDO apt-get update -qq && $SUDO apt-get install -y -qq nvidia-container-toolkit
                $SUDO nvidia-ctk runtime configure --runtime=docker
                $SUDO systemctl restart docker
            fi
            BASH,
            default => '',
        };
    }

    private function proxyScript(Node $node): string
    {
        // nginx templates the secure-token key at boot; an empty one renders `key ;` and the
        // container crashloops on an emerg. Fail here with a message that names the fix instead.
        $cdn = SelfHostedConfigData::from(app(CdnSettings::class)->providers['self_hosted'] ?? []);

        if ($cdn->tokenSecret === '') {
            throw new \RuntimeException('CDN token secret is empty. Set it in CDN Settings before deploying a proxy node.');
        }

        $image = $this->resolveImage('proxy');
        $name = $node->serviceContainerName();

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
        echo "=== Proxy image ==="
        {$this->imageStep($image, 'proxy-prod')}

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
        $storeName = $node->storageContainerName();
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
            // Prefixed like the containers: a development store must never be handed the volume
            // holding the fleet's mirrored sources and chunks.
            'volumes' => [Node::containerPrefix().'_chunks:/data'],
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

    /**
     * Vector only earns its keep on a self-hosted edge. It ships exactly one thing — the
     * `ip=/bytes=/video=` lines the vod nginx writes — into the bandwidth pipeline, and every other
     * container's logs are dropped by its own transform, so on a worker node it reads all of
     * Horizon's output to produce nothing. When the CDN is Bunny it has nothing to read at all:
     * viewer traffic never reaches our edge and {@see IngestBunnyLogs} polls Bunny's Logging API
     * into the very same pipeline instead. It was also the one deploy artifact with no node id in
     * its name, so every environment sharing a host fought over it.
     *
     * Deploying anywhere else therefore removes it, including where a previous deploy or a previous
     * CDN provider left one running.
     */
    private function vectorScript(Node $node, string $workdir): string
    {
        $name = Node::containerPrefix().'_vector';

        $collectsEdgeLogs = $node->type === NodeType::PROXY
            && app(CdnSettings::class)->provider === CdnDriver::SelfHosted->value;

        if (! $collectsEdgeLogs) {
            return <<<BASH
            echo "=== Vector not needed on this node — removing any leftover ==="
            docker rm -f {$name} 2>/dev/null || true
            BASH;
        }

        $image = 'timberio/vector:0.56.0-alpine';
        $vectorYaml = file_get_contents(base_path('vector/vector.yaml'));

        $runArgs = $this->buildDockerRunArgs($name, $image, [
            // Only the two variables the config interpolates. Handing a third-party image the whole
            // node environment — database, S3 and webhook credentials — bought nothing. Filtered
            // out of the merged list rather than rebuilt, so a node or global override of the
            // internal URL still reaches it.
            'env' => array_values(array_filter(
                $this->getEnvironmentVariables($node),
                fn ($v) => in_array(explode('=', $v, 2)[0], ['INTERNAL_API_URL', 'INTERNAL_API_SECRET'], true)
            )),
            'volumes' => [
                '/var/run/docker.sock:/var/run/docker.sock:ro',
                "{$workdir}/config/vector.yaml:/etc/vector/vector.yaml:ro",
            ],
        ]);

        return <<<BASH
        echo "=== Writing Vector config ==="
        cat > "\$WORKDIR/config/vector.yaml" << '__VECTOR_EOF__'
        {$vectorYaml}
        __VECTOR_EOF__

        echo "=== Deploying Vector ==="
        pull_image {$image}
        docker rm -f {$name} 2>/dev/null || true
        docker run -d {$runArgs}
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

        if ($node->accel) {
            $checks[] = ['key' => 'gpu', 'label' => 'GPU Encode'];
        }

        $results = [];

        foreach ($checks as $check) {
            try {
                $output = match ($check['key']) {
                    'docker' => $this->ssh($node, 'docker --version && docker info --format "Server: {{.ServerVersion}}"', 15),
                    'network' => $this->ssh($node, 'docker network inspect nukevideo_default --format "{{.Name}} ({{.Driver}})"', 15),
                    'containers' => $this->ssh($node, 'docker ps --filter '.escapeshellarg('name='.$this->containerFilter()).' --format "{{.Names}}\t{{.Status}}"', 15),
                    'disk' => $this->ssh($node, 'df -h / | tail -1', 15),
                    'gpu' => $this->ssh($node, $this->gpuProbeCommand($node), 120),
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

    /**
     * What "this installation's containers" means to `docker ps`. The filter is a regex over a
     * substring, so a bare `nukevideo_` also reports the `nukevideo_dev_*` containers of a
     * development install sharing the host — and the development panel would report production's.
     * The alternation is what keeps the production filter from swallowing the dev prefix.
     */
    private function containerFilter(): string
    {
        return app()->isLocal()
            ? 'nukevideo_dev_'
            : 'nukevideo_(worker|proxy|storage|vector|traefik)';
    }

    /**
     * A real hardware encode of a synthetic second inside the worker image — proof the GPU,
     * its driver and the container flags all line up, not just that a device file exists.
     */
    private function gpuProbeCommand(Node $node): string
    {
        $image = $this->resolveImage('api');
        // --entrypoint ffmpeg: the probe has no DB/Redis env, so the image's entrypoint
        // (migrations/optimize) must not run — only the encoder matters here.
        $run = 'docker run --rm --entrypoint ffmpeg';
        $probe = '-hide_banner -v error -f lavfi -i testsrc2=duration=1:size=640x360:rate=30';

        return match ($node->accel) {
            NodeAccel::INTEL => "{$run} --device /dev/dri --group-add \"\$(getent group render | cut -d: -f3)\" "
                ."{$image} {$probe} -c:v h264_qsv -f null - && echo 'QSV hardware encode OK'",
            NodeAccel::NVIDIA => "{$run} --gpus all {$image} {$probe} -c:v h264_nvenc -f null -"
                ." && echo 'NVENC hardware encode OK'",
            default => throw new \RuntimeException('Node has no GPU to probe.'),
        };
    }

    /**
     * A node image built in development gets its own tag, never `:dev`. That one belongs to
     * compose — it builds it from the `api-dev` target, which is the runtime with no application
     * code in it, and the next `docker compose up --build` would rebuild it from under a node that
     * needs the opposite. This tag is built from the release targets, so what a node runs in
     * development is shaped exactly like production and can be pushed or copied to an external test
     * node as it stands.
     */
    private const DEV_NODE_TAG = 'node-dev';

    /**
     * Pinned on APP_ENV rather than read from APP_VERSION: the version is a release concept and
     * means nothing to a working copy, and a development panel must not be able to put a released
     * production image on a node by accident.
     */
    private function resolveImage(string $type): string
    {
        $tag = app()->isLocal() ? self::DEV_NODE_TAG : config('app.version');

        return $this->registry()."/nukevideo-{$type}:{$tag}";
    }

    /**
     * The host part of every image name. Unset means Docker Hub, under the namespace the releases
     * are published in — so production says nothing and keeps pulling exactly what it always did.
     */
    private function registry(): string
    {
        return rtrim((string) config('nuke.registry'), '/') ?: 'chikenare';
    }

    /**
     * Production pulls the released tag. Development builds it, because there is nothing published
     * to pull and the point of deploying a development node is to run the code as it is right now.
     *
     * Which of the two happens is decided on the node, not here, because that is where the answer
     * is: the build context is the compose project's directory, read back from the label docker
     * wrote on the containers running the panel — `base_path()` cannot answer it, that is the path
     * *inside* the API container. A node that is the development machine has the working copy and
     * builds; an external test node does not, and pulls what the last build pushed. Same script,
     * and the deploy prints which of the two it did and from where.
     *
     * The push is what joins the two halves, so it only happens with a registry configured. Without
     * one the name resolves to Docker Hub, and a development build has no business being pushed to
     * the place releases are published.
     */
    private function imageStep(string $image, string $target): string
    {
        if (! app()->isLocal()) {
            return "pull_image {$image}";
        }

        $push = config('nuke.registry')
            ? "    docker push {$image}"
            : "    echo \"No DOCKER_REGISTRY set — {$image} stays on this host\"";

        return <<<BASH
        SOURCE_DIR=\$(docker ps -a --filter label=com.docker.compose.service=nukevideo-api \
            --format '{{.Label "com.docker.compose.project.working_dir"}}' | head -n1)
        if [ -n "\$SOURCE_DIR" ]; then
            echo "Building {$image} (target {$target}) from \$SOURCE_DIR"
            docker build --target {$target} -t {$image} "\$SOURCE_DIR"
        {$push}
        else
            echo "No working copy on this host — using {$image} as last published"
            pull_image {$image}
        fi
        BASH;
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

        foreach ($options['devices'] ?? [] as $device) {
            $cmd .= ' --device '.escapeshellarg($device);
        }
        if (! empty($options['gpus'])) {
            $cmd .= ' --gpus all';
        }
        if (! empty($options['group_add'])) {
            // Raw on purpose: the value may be a shell expansion resolved on the node.
            $cmd .= " --group-add {$options['group_add']}";
        }

        if (! empty($options['stop_timeout'])) {
            $cmd .= ' --stop-timeout '.(int) $options['stop_timeout'];
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
