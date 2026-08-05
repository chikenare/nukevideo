<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Same class of landmine as videos.template_id: node_id cascaded on delete, so decommissioning a
 * worker would have taken every video that ran on it — observers skipped, S3 objects orphaned.
 * Nothing writes the column today, which is the only reason it never fired. The column is already
 * nullable, so this is just the FK rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropForeign(['node_id']);
            $table->foreign('node_id')->references('id')->on('nodes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropForeign(['node_id']);
            $table->foreign('node_id')->references('id')->on('nodes')->cascadeOnDelete();
        });
    }
};
