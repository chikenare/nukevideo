<?php

declare(strict_types=1);

use Cog\Laravel\Clickhouse\Migration\AbstractClickhouseMigration;

return new class extends AbstractClickhouseMigration
{
    /**
     * Adds the caller-supplied tracking id to the bandwidth table, so a transfer can be attributed
     * to the integrator's own customer rather than only to a video and an IP.
     *
     * The column has to join the sorting key, not just sit beside it: this is a SummingMergeTree,
     * so two rows differing only in `tid` would be merged into one and the dimension would vanish
     * on the first background merge.
     *
     * Two constraints ClickHouse enforces, both discovered the hard way:
     *   - the column must be added in the SAME statement as MODIFY ORDER BY, or it counts as an
     *     "existing column" and the alter is refused;
     *   - it must carry NO default expression, or the alter is refused for that reason instead.
     *     The implicit type default still applies, so rows written before this keep an empty `tid`
     *     and their bytes intact — nothing is rewritten and nothing is lost.
     *
     * `LowCardinality` because a tracking id names a customer, of which there are few; a per-session
     * or per-request id would defeat both the encoding and the aggregation.
     */
    public function up(): void
    {
        $this->clickhouseClient->write(
            <<<'SQL'
                ALTER TABLE video_usage
                    ADD COLUMN IF NOT EXISTS tid LowCardinality(String),
                    MODIFY ORDER BY (user_id, video_ulid, ip, date, tid);
            SQL,
        );
    }
};
