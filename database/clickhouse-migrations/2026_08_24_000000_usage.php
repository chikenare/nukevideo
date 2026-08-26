<?php

declare(strict_types=1);

use App\Jobs\IngestBandwidthJob;
use Cog\Laravel\Clickhouse\Migration\AbstractClickhouseMigration;

return new class extends AbstractClickhouseMigration
{
    /**
     * The single metrics table, in its final shape. Consolidates the whole earlier chain
     * (usage, video_usage, the tid → tracking_id rename, the node/cache dimensions) into one
     * CREATE: the history had no readers, and a fresh install has nothing to carry over.
     *
     * Deliberately not upgrade-aware: an install that ran the earlier chain must `DROP TABLE usage`
     * (the history is not carried) before running this, or `IF NOT EXISTS` leaves the old shape —
     * `tid`, no `tracking_id`/`node_id`/`cache` — in place and the ingest and analytics break on it.
     *
     * Design notes that used to live across those migrations:
     *
     * - SummingMergeTree folds rows sharing the full sorting key as it merges, so a row can only
     *   ever be added — corrections are new rows, never updates.
     * - `value` is a shared Float64 whose unit lives in the metric name, deliberately: it means
     *   seconds for `encoding_cpu` and bytes for the delivery metrics, and nothing in the schema
     *   says which.
     * - `external_user_id` is the integrator's owner label, resolved server-side from the video
     *   ({@see IngestBandwidthJob}) so a viewer rewriting a link cannot change who the
     *   bytes are billed to. `tracking_id` is the caller's own per-link viewer label, read back
     *   from CDN access logs and therefore clamped, never trusted.
     * - `ip` is IPv6 (v4 arrives mapped); rows whose address cannot parse are dropped at ingest,
     *   because ClickHouse rejects a whole insert block over one bad value.
     * - `node_id` is 0 and `cache` '' for rows that predate those dimensions or do not have them
     *   (upload volume, encoding seconds).
     */
    public function up(): void
    {
        $this->clickhouseClient->write(
            <<<'SQL'
                CREATE TABLE IF NOT EXISTS usage (
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
                ORDER BY (user_id, metric, external_user_id, date, video_ulid, ip, tracking_id, node_id, cache)
            SQL,
        );
    }

    public function down(): void
    {
        $this->clickhouseClient->write('DROP TABLE IF EXISTS usage');
    }
};
