# Configuration

NukeVideo is configured through environment variables. This page documents all available settings.

The **Used in** column indicates where each variable is used:

- **API** — The Laravel application
- **Proxy** — Nginx VOD proxy nodes
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
| `REDIS_CLIENT` | `phpredis` | API | Redis client (`phpredis` or `predis`) |

## S3 Storage

| Variable | Default | Used in | Description |
|----------|---------|---------|-------------|
| `AWS_ACCESS_KEY_ID` | — | API, Proxy | S3 access key |
| `AWS_SECRET_ACCESS_KEY` | — | API, Proxy | S3 secret key |
| `AWS_DEFAULT_REGION` | `us-east-1` | API, Proxy | S3 region |
| `AWS_BUCKET` | — | API, Proxy | S3 bucket name |
| `AWS_ENDPOINT` | — | API, Proxy | S3 endpoint URL (for MinIO/RustFS) |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `false` | API | Use path-style URLs (required for MinIO) |

## VOD Proxy

These variables configure the Nginx VOD module on proxy nodes. They are substituted into the Nginx config via `envsubst` at container startup.

| Variable | Default | Used in | Description |
|----------|---------|---------|-------------|
| `VOD_SEGMENT_DURATION` | `10000` | Proxy | HLS/DASH segment duration in ms |
| `VOD_METADATA_CACHE_SIZE` | `512m` | Proxy | Metadata cache size |
| `VOD_RESPONSE_CACHE_SIZE` | `128m` | Proxy | Response cache size |
| `VOD_MAPPING_CACHE_SIZE` | `5m` | Proxy | Mapping cache size |
| `API_UPSTREAM_HOST` | `nukevideo-api:8080` | Proxy | API server address for upstream mapping requests |
| `API_UPSTREAM_PROTO` | `http` | Proxy | API server protocol (`http` or `https`) |
| `VOD_TOKEN_SECRET` | — | API, Proxy | Secret for signing and validating VOD streaming tokens |
| `SECURE_TOKEN_EXPIRES_TIME` | `100d` | Proxy | Stream token expiration (e.g., `100d`, `24h`) |
| `SECURE_TOKEN_QUERY_EXPIRES_TIME` | `1h` | Proxy | Query token expiration |
| `VOD_CACHE_MAX_SIZE` | `10g` | Proxy | Max size for local proxy cache |
| `VOD_CACHE_INACTIVE` | `1h` | Proxy | Evict cached items not accessed within this period |
| `CDN_MODE` | `false` | Proxy | Set to `true` to disable local proxy cache (when behind a CDN) |

## ClickHouse (Analytics)

| Variable | Default | Used in | Description |
|----------|---------|---------|-------------|
| `CLICKHOUSE_HOST` | `clickhouse` | API | ClickHouse hostname |
| `CLICKHOUSE_PORT` | `8123` | API | ClickHouse HTTP port |
| `CLICKHOUSE_DATABASE` | `default` | API, Vector | ClickHouse database name |
| `CLICKHOUSE_USER` | `default` | API, Vector | ClickHouse username |
| `CLICKHOUSE_PASSWORD` | — | API, Vector | ClickHouse password |
| `CLICKHOUSE_ENDPOINT` | `http://clickhouse:8123` | Vector | Full ClickHouse URL used by Vector for log ingestion |


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

Proxy containers receive all variables from the [VOD Proxy](#vod-proxy) and [S3 Storage](#s3-storage) sections above.

### Worker Nodes

Worker containers receive their environment from the worker node environment configuration. Typically includes Redis connection details and encoding-related settings.

### Vector (Both Nodes)

Vector runs on both proxy and worker nodes as a log collector. It receives the ClickHouse variables (`CLICKHOUSE_ENDPOINT`, `CLICKHOUSE_DATABASE`, `CLICKHOUSE_USER`, `CLICKHOUSE_PASSWORD`) plus `NODE_ID` which is injected automatically per node.
