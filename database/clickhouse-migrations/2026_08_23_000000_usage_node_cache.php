<?php

declare(strict_types=1);

use App\Jobs\IngestBandwidthJob;
use Cog\Laravel\Clickhouse\Migration\AbstractClickhouseMigration;

return new class extends AbstractClickhouseMigration
{
    /**
     * Adds the two dimensions that tell an operator whether a proxy node needs more disk or the
     * fleet needs another node: which edge served the bytes, and whether they came out of its
     * cache. The origin's side of the same story — bytes the edge had to fetch from S3 — lands as
     * its own metric, `origin_bytes`, under account 0 ({@see IngestBandwidthJob}):
     * nothing a customer is billed for, so nothing that may surface in `/api/usage`.
     *
     * Dimensions go into the sorting key, as `tid` did before them, or the first background merge
     * of this SummingMergeTree folds a node's rows into its neighbour's. Same two ClickHouse rules
     * as the earlier alters: added in the same statement as MODIFY ORDER BY, no default expression,
     * and the key only ever extended. Rows from before take the type defaults — node 0, cache '' —
     * which the hit-ratio query excludes as "unknown" rather than counting as misses.
     */
    public function up(): void
    {
        $this->clickhouseClient->write(
            <<<'SQL'
                ALTER TABLE usage
                    ADD COLUMN IF NOT EXISTS node_id UInt16,
                    ADD COLUMN IF NOT EXISTS cache LowCardinality(String),
                    MODIFY ORDER BY (user_id, metric, external_user_id, date, video_ulid, ip, tid, node_id, cache);
            SQL,
        );
    }
};
