<?php

declare(strict_types=1);

use Cog\Laravel\Clickhouse\Migration\AbstractClickhouseMigration;

return new class extends AbstractClickhouseMigration
{
    /**
     * Renames `tid` to `tracking_id`: the one column of `usage` whose name said nothing. It is
     * the integrator's own label on a link — a customer, a campaign, an order — carried so the
     * transfer can be attributed, and `tracking_id` is what the docblocks and the public
     * parameter's documentation already call it. The public `tid` query parameter stays: it is
     * a contract with integrators, and short on purpose because it travels in every URL.
     *
     * ClickHouse refuses to rename a column that is part of the sorting key
     * (ALTER_OF_COLUMN_IS_FORBIDDEN), so this is a rebuild: the table is recreated under the
     * final schema, every row copied across, and the two swapped in one atomic RENAME. The copy
     * is guarded by a checksum on the summed value before the old table goes, and the whole thing is idempotent —
     * a table that already has the column is left alone. Run it while ingestion is quiet: a
     * batch written to the old table between the copy and the swap would be lost with it.
     */
    public function up(): void
    {
        if (! $this->hasColumn('usage', 'tid')) {
            return;
        }

        $this->clickhouseClient->write('DROP TABLE IF EXISTS usage_new');

        $this->clickhouseClient->write(
            <<<'SQL'
                CREATE TABLE usage_new (
                    user_id UInt32,
                    metric LowCardinality(String),
                    external_user_id LowCardinality(String) DEFAULT '',
                    value Float64,
                    date Date,
                    video_ulid LowCardinality(String),
                    ip IPv6,
                    tracking_id LowCardinality(String),
                    node_id UInt16,
                    cache LowCardinality(String)
                ) ENGINE = SummingMergeTree(value)
                PARTITION BY toYYYYMM(date)
                PRIMARY KEY (user_id, metric, external_user_id, date)
                ORDER BY (user_id, metric, external_user_id, date, video_ulid, ip, tracking_id, node_id, cache);
            SQL,
        );

        $this->clickhouseClient->write(
            <<<'SQL'
                INSERT INTO usage_new (user_id, metric, external_user_id, value, date, video_ulid, ip, tracking_id, node_id, cache)
                SELECT user_id, metric, external_user_id, value, date, video_ulid, ip, tid, node_id, cache
                FROM usage;
            SQL,
        );

        // Compared by the summed value, not by row count: a SummingMergeTree folds rows with an
        // equal key as it writes a block, so the copy legitimately holds fewer rows than the source
        // while every byte and every second is still there.
        $before = (float) $this->clickhouseClient->select('SELECT sum(value) AS v FROM usage')->fetchOne('v');
        $after = (float) $this->clickhouseClient->select('SELECT sum(value) AS v FROM usage_new')->fetchOne('v');

        if (abs($after - $before) > 0.5) {
            $this->clickhouseClient->write('DROP TABLE usage_new');

            throw new RuntimeException("usage rebuild holds {$after} of {$before}; the old table is untouched.");
        }

        $this->clickhouseClient->write('RENAME TABLE usage TO usage_old, usage_new TO usage');
        $this->clickhouseClient->write('DROP TABLE usage_old');
    }

    private function hasColumn(string $table, string $column): bool
    {
        $count = $this->clickhouseClient
            ->select(
                'SELECT count() AS n FROM system.columns WHERE database = currentDatabase() AND table = {table:String} AND name = {column:String}',
                ['table' => $table, 'column' => $column],
            )
            ->fetchOne('n');

        return (int) $count > 0;
    }
};
