<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            // Keep the encoded video/audio renditions on primary S3 after packaging, or drop them
            // and serve only the CMAF package. See PackageVideoJob::pruneProcessedRenditions().
            $table->boolean('keep_processed_files')->default(true)->after('query');
        });
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropColumn('keep_processed_files');
        });
    }
};
