<?php

declare(strict_types=1);

use Cog\Laravel\Clickhouse\Migration\AbstractClickhouseMigration;

return new class extends AbstractClickhouseMigration
{
    public function up(): void
    {
        $this->clickhouseClient->write(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS usage (
                    user_id UInt32,
                    metric LowCardinality(String),
                    external_user_id LowCardinality(String) DEFAULT '',
                    value Float64,
                    date Date
                ) ENGINE = SummingMergeTree(value)
                PARTITION BY toYYYYMM(date)
                ORDER BY (user_id, metric, external_user_id, date)
                TTL date + INTERVAL 5 YEAR;
            SQL,
        );
    }
};
