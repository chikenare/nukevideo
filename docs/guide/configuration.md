# Configuration

NukeVideo is configured through environment variables. This page documents all available settings.

The **Used in** column indicates where each variable is used:

- **API** — The Laravel application
- **Proxy** — Self-hosted CMAF delivery proxy nodes
- **Worker** — FFmpeg encoding nodes
- **Vector** — Log collector (runs on both proxy and worker nodes)

## Application

| Variable | Default | Used in | Description |
|----------|---------|---------|-------------|
| `APP_ENV` | `local` | API | Environment (`local`, `production`) |
| `APP_DEBUG` | `true` | API | Enable debug mode (disable in production) |
| `APP_URL` | `http://localhost` | API | Base URL for the API |
| `APP_KEY` | — | API | Application encryption key (generate with `php artisan key:generate`) |
| `WEBHOOK_SECRET` | — | API | Secret for validating video upload webhooks |

## Database

| Variable | Default | Used in | Description |
|----------|---------|---------|-------------|
| `DB_CONNECTION` | `mysql` | API | Database driver |
| `DB_HOST` | `db` | API | Database hostname |
| `DB_PORT` | `3306` | API | Database port |
| `DB_DATABASE` | `laravel` | API | Database name |
| `DB_USERNAME` | `root` | API | Database user |
| `DB_PASSWORD` | — | API | Database password |

## Redis

| Variable | Default | Used in | Description |
|----------|---------|---------|-------------|
| `REDIS_HOST` | `127.0.0.1` | API | Redis hostname |
| `REDIS_PORT` | `6379` | API | Redis port |
| `REDIS_PASSWORD` | `null` | API | Redis password |
| `REDIS_CLIENT` | `phpredis` | API | Redis client |

## S3 Storage

| Variable | Default | Used in | Description |
|----------|---------|---------|-------------|
| `AWS_ACCESS_KEY_ID` | — | API, Proxy | S3 access key |
| `AWS_SECRET_ACCESS_KEY` | — | API, Proxy | S3 secret key |
| `AWS_DEFAULT_REGION` | `us-east-1` | API, Proxy | S3 region |
| `AWS_BUCKET` | — | API, Proxy | S3 bucket name |
| `AWS_ENDPOINT` | — | API, Proxy | S3 endpoint URL (for MinIO/RustFS) |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `false` | API | Use path-style URLs (required for MinIO) |

## Proxy Node Delivery

These values control token validation on self-hosted proxy nodes, which serve the pre-packaged CMAF from S3. They are **not** set in `.env` — they live in the **CDN Settings** panel (the `self_hosted` provider) and are injected into the proxy's nginx container at deploy time under the names below. Leaving one empty falls back to the container's default.

The segment cache itself is not configured: the node's deploy builds it from the host's spare disks and the edge sizes it to that pool on boot. See [Cache disks](/guide/nodes#cache-disks).

| Variable | Default | Used in | Description |
|----------|---------|---------|-------------|
| `VOD_TOKEN_SECRET` | — | Proxy | Secret for signing and validating stream tokens |
| `VOD_TOKEN_NAME` | `__hdnea__` | Proxy | Query argument carrying the token. Lowercase, digits and `_` only; changing it requires redeploying the proxy, and links already signed with the old name stop validating |
| `SECURE_TOKEN_EXPIRES_TIME` | `100d` | Proxy | `Cache-Control`/`Expires` the edge puts on responses it does not tokenize (segments), e.g. `100d`, `24h`. Not a token lifetime: the link's token is minted by the API with the provider's token window, and the edge propagates it to the segments |
| `SECURE_TOKEN_QUERY_EXPIRES_TIME` | `1h` | Proxy | `Cache-Control`/`Expires` on the rewritten manifests, which carry the token in their segment URLs |

## CDN

Delivery is chosen per deployment in the admin panel under **CDN Settings**, using the `provider` field:

- `self_hosted` — Deliver through your own proxy nodes (uses the [Proxy Node Delivery](#proxy-node-delivery) settings above).
- `bunny` — Deliver through a Bunny CDN pull-zone pointed at your S3 origin. Configure the pull-zone **host**, **token key**, and **token window** in the panel.

Bunny is configured entirely from the panel (stored in the database), not through `.env`. See [CDN & Delivery](/guide/cdn) for details on both modes.

## Video Processing

Variables that control FFmpeg concurrency on worker nodes.

| Variable | Default | Used in | Description |
|----------|---------|---------|-------------|
| `VIDEO_WORKER_PROCESSES` | auto | Worker | Concurrent FFmpeg processes per node. Auto-sized from the hardware the node sees: the smaller of `floor(cores / 4)` and `floor(usable RAM / 3 GB)`, where usable RAM is total minus 20% (min 2 GB) left to the OS, the chunk store and packaging. Never below 1. Explicit value wins. |
| `VIDEO_FFMPEG_THREADS` | auto | Worker | Threads per encoder. Defaults to the node's fair share, `floor(cores / VIDEO_WORKER_PROCESSES)`, capped at 8 — so processes × threads fills the CPU without oversubscribing it. Explicit value wins. |
| `VIDEO_WORKER_TIMEOUT` | `600` | Worker | Per-chunk wall-clock ceiling in seconds. FFmpeg gets this minus 120 s. Raise it for slow codecs (x265/AV1) at low thread counts. In production NodeService injects this per worker container. |

On a node that shares its box with other services, set `DOCKER_MEMORY` (and optionally `DOCKER_CPUSET_CPUS`) in the node's env from the panel: the worker container gets that limit, the auto-sizing above budgets against it instead of the whole host, and an encode that overruns takes the worker down — not whatever else the host is running.

## ClickHouse (Analytics)

| Variable | Default | Used in | Description |
|----------|---------|---------|-------------|
| `CLICKHOUSE_HOST` | `clickhouse` | API | ClickHouse hostname |
| `CLICKHOUSE_PORT` | `8123` | API | ClickHouse HTTP port |
| `CLICKHOUSE_DATABASE` | `default` | API | ClickHouse database name |
| `CLICKHOUSE_USER` | `default` | API | ClickHouse username |
| `CLICKHOUSE_PASSWORD` | — | API | ClickHouse password |
| `CLICKHOUSE_ENDPOINT` | `http://clickhouse:8123` | API | Full ClickHouse URL the API writes usage to. Vector never talks to ClickHouse: it posts to the API's internal bandwidth endpoint and receives only `INTERNAL_API_URL` and `INTERNAL_API_SECRET` |


## Monitoring

| Variable | Default | Used in | Description |
|----------|---------|---------|-------------|
| `SENTRY_LARAVEL_DSN` | — | API | Sentry DSN for error tracking |
| `SENTRY_TRACES_SAMPLE_RATE` | `1.0` | API | Sentry trace sampling rate (0.0 – 1.0) |

## Mail

| Variable | Default | Used in | Description |
|----------|---------|---------|-------------|
| `MAIL_MAILER` | `log` | API | Mail driver (`smtp`, `ses`, `log`) |
| `MAIL_HOST` | `127.0.0.1` | API | SMTP host |
| `MAIL_PORT` | `2525` | API | SMTP port |
| `MAIL_USERNAME` | `null` | API | SMTP username |
| `MAIL_PASSWORD` | `null` | API | SMTP password |

## Node Environment Variables

Variables for proxy and worker nodes are managed through the UI or API at `/api/node-environments/{type}`. These are injected into Docker containers at deploy time.

### Proxy Nodes

Proxy containers receive the [S3 Storage](#s3-storage) variables (to read packaged CMAF from the bucket) plus the token settings from [Proxy Node Delivery](#proxy-node-delivery), which are sourced from the CDN Settings panel.

### Worker Nodes

Worker containers receive their environment from the worker node environment configuration. Typically includes Redis connection details and encoding-related settings.

### Vector (Proxy Nodes, self-hosted CDN only)

Vector runs on proxy nodes when the CDN provider is `self_hosted` — nothing else produces the access-log lines it ships, and a deploy removes it anywhere else. It reads the edge nginx's log from the Docker socket, sums bytes per video, viewer and link token on the node (the token is hashed there; it never leaves), and posts the aggregates to the API's internal bandwidth endpoint, which writes them to ClickHouse. Its config is rendered from `vector/vector.yaml` at deploy time; to watch what it emits on a node, `docker exec <vector container> vector tap aggregate_bandwidth_logs`.
