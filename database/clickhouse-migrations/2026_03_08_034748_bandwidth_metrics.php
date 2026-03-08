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
                        timestamp DateTime64(3),
                        ip String,
                        video String,
                        extid Nullable(String),
                        bytes UInt64
                    ) ENGINE = SummingMergeTree(bytes)
                    PARTITION BY toYYYYMM(timestamp)
                    ORDER BY (timestamp, video, extid, ip)
                    SETTINGS allow_nullable_key = 1;
                SQL,
        );
    }
};
