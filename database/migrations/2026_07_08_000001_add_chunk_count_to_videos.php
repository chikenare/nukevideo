<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Number of chunk windows planned at fan-out. The packager asserts each video rendition
    // concatenated exactly this many chunks, so a premature batch completion (a redelivered
    // chunk double-decrements the batch) can never publish a short rendition undetected.
    public function up(): void
    {
        if (Schema::hasColumn('videos', 'chunk_count')) {
            return;
        }

        Schema::table('videos', function (Blueprint $table) {
            $table->unsignedInteger('chunk_count')->nullable()->after('aspect_ratio');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('videos', 'chunk_count')) {
            return;
        }

        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn('chunk_count');
        });
    }
};
