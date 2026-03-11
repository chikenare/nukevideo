<?php

declare(strict_types=1);

use Cog\Laravel\Clickhouse\Migration\AbstractClickhouseMigration;

return new class extends AbstractClickhouseMigration
{
    public function up(): void
    {
        $this->clickhouseClient->write(
            <<<'SQL'
                    CREATE TABLE IF NOT EXISTS bandwidth_metrics (
                        date Date,
                        ip IPv6,
                        video LowCardinality(String),
                        extid LowCardinality(String),
                        bytes UInt64
                    ) ENGINE = SummingMergeTree(bytes)
                    PARTITION BY toYYYYMM(date)
                    ORDER BY (date, video, extid, ip)
                    TTL date + INTERVAL 1 YEAR;
                SQL,
        );
    }
};
