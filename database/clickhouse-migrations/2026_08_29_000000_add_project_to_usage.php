<?php

declare(strict_types=1);

use Cog\Laravel\Clickhouse\Migration\AbstractClickhouseMigration;

return new class extends AbstractClickhouseMigration
{
    /**
     * Give `usage` the project a row belongs to.
     *
     * Without it the table can only be scoped to an ACCOUNT, and an account has many projects — so
     * a project API key reading its own numbers was reading every sibling project's too, and the
     * reads that break down by an identifier (viewer addresses, viewer labels, video ULIDs) could
     * not be offered to a tenant at all, because there was no column to narrow them by. Most of the
     * per-dimension rules in the metrics API exist to work around exactly this absence.
     *
     * The ingest already loads the Video to resolve `user_id` and `external_user_id`, so writing one
     * more column off the same lookup costs nothing.
     *
     * Three things about the shape, none of them free choices:
     *
     * - `ADD COLUMN` and `MODIFY ORDER BY` must be ONE statement. ClickHouse refuses to add an
     *   existing column to a sorting key, and a column added by a previous ALTER counts as existing.
     * - The column takes NO `DEFAULT`. ClickHouse refuses to put a column with a default expression
     *   into a sorting key; without one, rows in older parts simply read the type's zero, which is
     *   the 0 that means "written before this column existed" — the same convention `node_id` uses.
     * - It is APPENDED to the sorting key rather than placed next to `user_id`, because only an
     *   append is possible on a populated table. That costs a prefix scan on project-filtered
     *   queries (they stay partition pruning plus granule skipping) and buys keeping the history:
     *   the alternative is DROP and CREATE. It is not merely an optimisation, though — being in the
     *   sorting key at all is what stops SummingMergeTree from folding two projects into one row for
     *   the metrics that carry no `video_ulid` to tell them apart, `upload_bytes` above all.
     *
     * No `down()`: the package ships `clickhouse:migrate` and nothing else, so there is no rollback
     * to write one for. The guard below is what makes re-running safe.
     */
    public function up(): void
    {
        if ($this->hasProjectColumn()) {
            return;
        }

        $this->clickhouseClient->write(
            <<<'SQL'
                ALTER TABLE usage
                    ADD COLUMN project_id UInt32 AFTER user_id,
                    MODIFY ORDER BY (user_id, metric, external_user_id, date, video_ulid, ip, tracking_id, node_id, cache, project_id)
            SQL,
        );
    }

    /** So the migration can be re-run against a database that already has it. */
    private function hasProjectColumn(): bool
    {
        $rows = $this->clickhouseClient->select(
            "SELECT name FROM system.columns WHERE database = currentDatabase() AND table = 'usage' AND name = 'project_id'",
        )->rows();

        return $rows !== [];
    }
};
