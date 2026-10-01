<?php

use App\Services\NodeService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The chunk store is located by the `node.chunk_store_address` setting now, which the settings
 * migration just before this one filled from the flagged node. Each worker's deploy decides on its
 * own host whether that address is its own ({@see NodeService::chunkStoreVars}), so
 * nothing on the node row says it anymore.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['is_storage_server', 'storage_endpoint'] as $column) {
            if (Schema::hasColumn('nodes', $column)) {
                Schema::table('nodes', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('nodes', 'is_storage_server')) {
            Schema::table('nodes', function (Blueprint $table) {
                $table->boolean('is_storage_server')->default(false);
                $table->string('storage_endpoint')->nullable();
            });
        }
    }
};
