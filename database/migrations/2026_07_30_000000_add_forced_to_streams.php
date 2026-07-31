<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('streams', 'forced')) {
            Schema::table('streams', function (Blueprint $table) {
                // Effective value served to players; `meta.forced` stays the raw ffprobe disposition.
                $table->boolean('forced')->default(false)->after('language');
            });
        }

        // Backfill in PHP rather than JSON_EXTRACT: MySQL in production, sqlite in tests.
        DB::table('streams')
            ->select('id', 'meta')
            ->where('type', 'subtitle')
            ->orderBy('id')
            ->chunk(500, function ($streams) {
                foreach ($streams as $stream) {
                    if (data_get(json_decode($stream->meta, true), 'forced')) {
                        DB::table('streams')->where('id', $stream->id)->update(['forced' => true]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('streams', function (Blueprint $table) {
            $table->dropColumn('forced');
        });
    }
};
