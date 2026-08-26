<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the playback URL resolver needs to route around a proxy node without stopping it.
 *
 * `is_active` was the only switch, and flipping it off stops the node's containers at once —
 * a hard kill of every in-flight session, which is the wrong tool both for a node that just
 * stopped answering and for one the operator wants to take out for maintenance. `is_draining`
 * keeps the node out of new playback links while it goes on serving the old ones; the health
 * columns let `nodes:probe` do the same on its own for a node that is down.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            if (! Schema::hasColumn('nodes', 'is_draining')) {
                $table->boolean('is_draining')->default(false)->after('is_active');
            }

            if (! Schema::hasColumn('nodes', 'health_failures')) {
                // Consecutive failed probes. Reset by a successful one, a deploy or a start.
                $table->unsignedTinyInteger('health_failures')->default(0)->after('is_draining');
            }

            if (! Schema::hasColumn('nodes', 'last_healthy_at')) {
                $table->timestamp('last_healthy_at')->nullable()->after('health_failures');
            }
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $present = array_filter(
                ['is_draining', 'health_failures', 'last_healthy_at'],
                fn (string $column) => Schema::hasColumn('nodes', $column),
            );

            if ($present !== []) {
                $table->dropColumn(array_values($present));
            }
        });
    }
};
