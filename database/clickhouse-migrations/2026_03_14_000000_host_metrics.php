<?php

declare(strict_types=1);

use Cog\Laravel\Clickhouse\Migration\AbstractClickhouseMigration;

return new class extends AbstractClickhouseMigration
{
    public function up(): void
    {

        $this->clickhouseClient->write(
            <<<'SQL'
                    CREATE TABLE IF NOT EXISTS host_metrics (
                        timestamp DateTime,
                        node_id UInt8,
                        metric LowCardinality(String),
                        value Float64
                    ) ENGINE = MergeTree()
                    PARTITION BY toYYYYMM(timestamp)
                    ORDER BY (node_id, metric, timestamp)
                    TTL timestamp + INTERVAL 1 MONTH;
                SQL,
        );
    }
};
