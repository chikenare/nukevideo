# Configuration

NukeVideo is configured through environment variables. This page documents all available settings.

## Application

| Variable | Default | Description |
|----------|---------|-------------|
| `APP_NAME` | `Laravel` | Application name |
| `APP_ENV` | `local` | Environment (`local`, `production`) |
| `APP_DEBUG` | `true` | Enable debug mode (disable in production) |
| `APP_URL` | `http://localhost` | Base URL for the API |
| `APP_KEY` | — | Application encryption key (generate with `php artisan key:generate`) |

## Database (MariaDB)

| Variable | Default | Description |
|----------|---------|-------------|
| `DB_CONNECTION` | `mysql` | Database driver |
| `DB_HOST` | `db` | Database hostname |
| `DB_PORT` | `3306` | Database port |
| `DB_DATABASE` | `laravel` | Database name |
| `DB_USERNAME` | `root` | Database user |
| `DB_PASSWORD` | — | Database password |

## Redis

| Variable | Default | Description |
|----------|---------|-------------|
| `REDIS_HOST` | `127.0.0.1` | Redis hostname |
| `REDIS_PORT` | `6379` | Redis port |
| `REDIS_PASSWORD` | `null` | Redis password |
| `REDIS_CLIENT` | `phpredis` | Redis client (`phpredis` or `predis`) |

## S3 Storage

| Variable | Default | Description |
|----------|---------|-------------|
| `AWS_ACCESS_KEY_ID` | — | S3 access key |
| `AWS_SECRET_ACCESS_KEY` | — | S3 secret key |
| `AWS_DEFAULT_REGION` | `us-east-1` | S3 region |
| `AWS_BUCKET` | — | S3 bucket name |
| `AWS_ENDPOINT` | — | S3 endpoint URL (for MinIO/RustFS) |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `false` | Use path-style URLs (required for MinIO) |

## Security & Tokens

| Variable | Default | Description |
|----------|---------|-------------|
| `WEBHOOK_SECRET` | — | Secret for validating video upload webhooks |
| `VOD_TOKEN_SECRET` | — | Secret for signing VOD streaming tokens |
| `SECURE_TOKEN_EXPIRES_TIME` | `100d` | Stream token expiration (e.g., `100d`, `24h`) |
| `SECURE_TOKEN_QUERY_EXPIRES_TIME` | `1h` | Query token expiration |

## VOD Proxy

These variables configure the Nginx VOD proxy nodes:

| Variable | Default | Description |
|----------|---------|-------------|
| `VOD_SEGMENT_DURATION` | `10000` | HLS/DASH segment duration in ms |
| `VOD_METADATA_CACHE_SIZE` | `512m` | Metadata cache size |
| `VOD_RESPONSE_CACHE_SIZE` | `128m` | Response cache size |
| `VOD_MAPPING_CACHE_SIZE` | `5m` | Mapping cache size |
| `API_UPSTREAM_HOST` | `nukevideo-api:8080` | API server address for the proxy |
| `API_UPSTREAM_PROTO` | `http` | API server protocol |

## ClickHouse (Analytics)

| Variable | Default | Description |
|----------|---------|-------------|
| `CLICKHOUSE_DATABASE` | — | ClickHouse database name |
| `CLICKHOUSE_USER` | — | ClickHouse username |
| `CLICKHOUSE_PASSWORD` | — | ClickHouse password |

## Queue

| Variable | Default | Description |
|----------|---------|-------------|
| `QUEUE_CONNECTION` | `database` | Queue driver (`redis` for production) |

## Monitoring

| Variable | Default | Description |
|----------|---------|-------------|
| `SENTRY_LARAVEL_DSN` | — | Sentry DSN for error tracking |
| `SENTRY_TRACES_SAMPLE_RATE` | `1.0` | Sentry trace sampling rate (0.0 – 1.0) |

## Mail

| Variable | Default | Description |
|----------|---------|-------------|
| `MAIL_MAILER` | `log` | Mail driver (`smtp`, `ses`, `log`) |
| `MAIL_HOST` | `127.0.0.1` | SMTP host |
| `MAIL_PORT` | `2525` | SMTP port |
| `MAIL_USERNAME` | `null` | SMTP username |
| `MAIL_PASSWORD` | `null` | SMTP password |
