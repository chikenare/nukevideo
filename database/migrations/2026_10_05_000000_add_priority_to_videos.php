<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pending videos were dispatched strictly by arrival, so a single urgent upload waited behind a
 * whole season queued minutes earlier. `priority` lets the uploader move it ahead (or push a bulk
 * load back); DispatchPendingVideosCommand orders by it before arrival. Every existing row
 * is `normal`, which keeps the queue exactly as it was.
 *
 * The index serves that one query: PENDING rows of one priority, walked by id. InnoDB appends the
 * primary key to every secondary index, so `(status, priority)` already orders by arrival.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('videos', 'priority')) {
            return;
        }

        Schema::table('videos', function (Blueprint $table) {
            $table->string('priority', 16)->default('normal')->after('status');
            $table->index(['status', 'priority'], 'videos_dispatch_order_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('videos', 'priority')) {
            return;
        }

        // Two statements: sqlite (the test database) rebuilds the table to drop a column, and
        // refuses to while an index still names it.
        Schema::table('videos', fn (Blueprint $table) => $table->dropIndex('videos_dispatch_order_index'));
        Schema::table('videos', fn (Blueprint $table) => $table->dropColumn('priority'));
    }
};
