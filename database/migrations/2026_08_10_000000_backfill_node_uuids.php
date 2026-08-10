<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * `uuid` was added to `nodes` as nullable with no backfill, and only NodeObserver::creating fills
 * it — so every node that existed before that migration still has NULL. Two consequences, both
 * silent until they are not:
 *
 *   - `NodeData::fromModel()` passes it into a non-nullable `string`, so ONE legacy row makes the
 *     whole `GET /api/nodes` response a TypeError and the Nodes page unusable.
 *   - `NodeService::workdir()` builds `/home/{user}/nukevideo/node-{uuid}`, which collapses to a
 *     path every legacy node shares — their `config/vector.yaml` files overwrite each other.
 *
 * Backfills row by row (each uuid must be distinct) and leaves the column nullable: making it
 * NOT NULL is a separate decision, and this migration has to stay re-runnable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('nodes', 'uuid')) {
            return;
        }

        // chunkById, not chunk: the callback writes the very column the filter selects on, so an
        // offset-based pager would skip a whole page each time the result set shrank underneath it.
        DB::table('nodes')->whereNull('uuid')->select('id')->chunkById(100, function ($nodes) {
            foreach ($nodes as $node) {
                DB::table('nodes')->where('id', $node->id)->update(['uuid' => (string) Str::uuid()]);
            }
        });
    }

    /**
     * Irreversible by design: the previous state is "NULL", and restoring it would put back the
     * exact breakage this migration exists to clear.
     */
    public function down(): void
    {
        //
    }
};
