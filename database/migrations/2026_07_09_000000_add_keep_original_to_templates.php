<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('templates', 'keep_original')) {
            return;
        }

        Schema::table('templates', function (Blueprint $table) {
            // Retain the uploaded source on primary S3 after a successful run, instead of reclaiming
            // it. See CleanupVideoResourcesJob. Defaults off: today every original is dropped.
            $table->boolean('keep_original')->default(false)->after('keep_processed_files');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('templates', 'keep_original')) {
            return;
        }

        Schema::table('templates', function (Blueprint $table) {
            $table->dropColumn('keep_original');
        });
    }
};
