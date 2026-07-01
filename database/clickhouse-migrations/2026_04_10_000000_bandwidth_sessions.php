<?php

declare(strict_types=1);

use Cog\Laravel\Clickhouse\Migration\AbstractClickhouseMigration;

return new class extends AbstractClickhouseMigration
{
    public function up(): void
    {

        $this->clickhouseClient->write(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS sessions (
                    date Date DEFAULT today(),
                    session_id LowCardinality(String),
                    user_id UInt32,
                    video_ulid LowCardinality(String),
                    output_ulid LowCardinality(String),
                    external_resource_id LowCardinality(String),
                    external_user_id LowCardinality(String),
                    ip IPv6,
                    bytes UInt64
                ) ENGINE = SummingMergeTree(bytes)
                PARTITION BY toYYYYMM(date)
                ORDER BY (session_id, user_id, video_ulid, output_ulid, external_resource_id, external_user_id, ip, date)
                TTL date + INTERVAL 1 YEAR;
            SQL,
        );
    }
};
