<?php

declare(strict_types=1);

use Cog\Laravel\Clickhouse\Migration\AbstractClickhouseMigration;

return new class extends AbstractClickhouseMigration
{
    public function up(): void
    {
        $this->clickhouseClient->write(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS video_usage (
                    date Date DEFAULT today(),
                    user_id UInt32,
                    video_ulid LowCardinality(String),
                    ip IPv6,
                    bytes UInt64
                ) ENGINE = SummingMergeTree(bytes)
                PARTITION BY toYYYYMM(date)
                ORDER BY (user_id, video_ulid, ip, date)
                TTL date + INTERVAL 1 YEAR;
            SQL,
        );
    }
};
