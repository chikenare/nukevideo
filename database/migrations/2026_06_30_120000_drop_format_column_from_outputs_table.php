<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `outputs.format` was a stray, untracked column on some environments (never defined by a
     * migration — the create-table migration already documents that streaming formats are derived
     * from streams' codecs via Output::formats(), not stored). An output no longer targets a single
     * format at all: it always serves whichever of HLS/DASH its codecs support. Drop it if present.
     */
    public function up(): void
    {
        if (Schema::hasColumn('outputs', 'format')) {
            Schema::table('outputs', function (Blueprint $table) {
                $table->dropColumn('format');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('outputs', 'format')) {
            Schema::table('outputs', function (Blueprint $table) {
                $table->enum('format', ['hls', 'dash'])->nullable();
            });
        }
    }
};
