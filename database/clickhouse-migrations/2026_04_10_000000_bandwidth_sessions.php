<?php

declare(strict_types=1);

use Cog\Laravel\Clickhouse\Migration\AbstractClickhouseMigration;

return new class extends AbstractClickhouseMigration
{
    public function up(): void
    {

        $this->clickhouseClient->write(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS sessions_active (
                    session_id String,
                    user_id UInt32,
                    video_ulid String,
                    output_ulid String,
                    external_resource_id String DEFAULT '',
                    created_at DateTime DEFAULT now()
                ) ENGINE = MergeTree()
                ORDER BY (session_id)
                TTL created_at + INTERVAL 1 HOUR;
            SQL,
        );

        $user = config('clickhouse.user', env('CLICKHOUSE_USER', 'default'));
        $password = config('clickhouse.password', env('CLICKHOUSE_PASSWORD', ''));

        $this->clickhouseClient->write(
            <<<SQL
                CREATE DICTIONARY IF NOT EXISTS sessions_dict (
                    session_id String,
                    user_id UInt32,
                    video_ulid String,
                    output_ulid String,
                    external_resource_id String
                ) PRIMARY KEY session_id
                SOURCE(CLICKHOUSE(TABLE 'sessions_active' USER '{$user}' PASSWORD '{$password}'))
                LIFETIME(MIN 10 MAX 30)
                LAYOUT(HASHED());
            SQL,
        );

        $this->clickhouseClient->write(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS bandwidth_logs (
                    created_at DateTime DEFAULT now(),
                    session_id String,
                    ip IPv6,
                    bytes UInt64
                ) ENGINE = MergeTree()
                ORDER BY (session_id, ip)
                TTL created_at + INTERVAL 1 DAY;
            SQL,
        );

        $this->clickhouseClient->write(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS sessions (
                    timestamp DateTime DEFAULT now(),
                    session_id LowCardinality(String),
                    user_id UInt32,
                    video_ulid LowCardinality(String),
                    output_ulid LowCardinality(String),
                    external_resource_id LowCardinality(String),
                    ip IPv6,
                    bytes UInt64
                ) ENGINE = SummingMergeTree(bytes)
                PARTITION BY toYYYYMM(timestamp)
                ORDER BY (session_id, user_id, video_ulid, output_ulid, external_resource_id, ip, timestamp)
                TTL timestamp + INTERVAL 1 YEAR;
            SQL,
        );

        $this->clickhouseClient->write(
            <<<'SQL'
                CREATE MATERIALIZED VIEW IF NOT EXISTS sessions_mv
                TO sessions
                AS
                SELECT
                    session_id,
                    dictGet('sessions_dict', 'user_id', session_id) AS user_id,
                    dictGet('sessions_dict', 'video_ulid', session_id) AS video_ulid,
                    dictGet('sessions_dict', 'output_ulid', session_id) AS output_ulid,
                    dictGet('sessions_dict', 'external_resource_id', session_id) AS external_resource_id,
                    ip,
                    bytes
                FROM bandwidth_logs
                WHERE session_id != '';
            SQL,
        );
    }
};
