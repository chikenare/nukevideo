<?php

declare(strict_types=1);

use Cog\Laravel\Clickhouse\Migration\AbstractClickhouseMigration;

return new class extends AbstractClickhouseMigration
{
    /**
     * Folds `video_usage` into `usage`, leaving one metrics table.
     *
     * The two held the same shape at different granularities — a SummingMergeTree of a numeric
     * counter keyed by account and day — and the split meant delivery bandwidth was only ever
     * readable from `/api/analytics`, never from `/api/usage`, which is the endpoint an external
     * project polls. Merging also buys a dimension the old shape could not express: `metric` now
     * separates streaming from downloads, which `video_usage` collapsed into one `bytes` column.
     *
     * Two ClickHouse rules shape the statement below, both learned the hard way here:
     *   - a column must be added in the SAME statement as MODIFY ORDER BY, or the alter is refused
     *     for referencing an "existing column";
     *   - the sorting key can only be EXTENDED, never reordered, so the new columns land after
     *     `date` rather than beside the ones they belong with. The PRIMARY KEY stays the original
     *     four-column prefix, which is what makes this an in-place alter and not a table rebuild.
     *
     * Deliberately **no TTL**. `video_usage` expired at a year because it holds viewer IPs; usage
     * history is billing data and is kept indefinitely. One table cannot honour both policies, and
     * keeping everything is the explicit choice here.
     *
     * Rows already in `usage` (`upload_bytes`, `encoding_cpu`) keep their values untouched and take
     * the implicit type defaults for the new columns — '' and `::` — which is exactly right: those
     * metrics have no video, viewer or tracking id.
     */
    public function up(): void
    {
        $this->clickhouseClient->write(
            <<<'SQL'
                ALTER TABLE usage
                    ADD COLUMN IF NOT EXISTS video_ulid LowCardinality(String),
                    ADD COLUMN IF NOT EXISTS ip IPv6,
                    ADD COLUMN IF NOT EXISTS tid LowCardinality(String),
                    MODIFY ORDER BY (user_id, metric, external_user_id, date, video_ulid, ip, tid);
            SQL,
        );

        // Carry the history over before the table goes. `INSERT SELECT` is not idempotent and a
        // SummingMergeTree would happily double every byte, so it runs only when nothing has been
        // carried yet — the migration ledger already prevents a second run, this covers a manual one.
        $carried = $this->clickhouseClient
            ->select("SELECT count() AS n FROM usage WHERE metric = 'bandwidth_bytes'")
            ->fetchOne('n');

        if ((int) $carried === 0 && $this->tableExists('video_usage')) {
            // `bandwidth_bytes`, not `streaming_bytes`/`download_bytes`: the old table never
            // recorded which zone served a request, so splitting the history now would be inventing
            // it. The generic metric says exactly what is known. `external_user_id` stays empty for
            // the same reason — it lives in MySQL and cannot be resolved from inside ClickHouse.
            $this->clickhouseClient->write(
                <<<'SQL'
                    INSERT INTO usage (date, user_id, metric, external_user_id, video_ulid, ip, tid, value)
                    SELECT date, user_id, 'bandwidth_bytes', '', video_ulid, ip, tid, bytes
                    FROM video_usage;
                SQL,
            );
        }

        $this->clickhouseClient->write('DROP TABLE IF EXISTS video_usage');
    }

    private function tableExists(string $table): bool
    {
        $count = $this->clickhouseClient
            ->select('SELECT count() AS n FROM system.tables WHERE database = currentDatabase() AND name = {table:String}', ['table' => $table])
            ->fetchOne('n');

        return (int) $count > 0;
    }
};
