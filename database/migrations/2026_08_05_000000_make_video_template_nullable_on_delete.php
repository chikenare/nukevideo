<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * videos.template_id cascaded on delete: removing a template silently dropped every video encoded
 * with it — and a DB-level cascade skips the Eloquent observers, so their S3 objects leaked too.
 * The controller now refuses to delete a template with videos; this is the backstop for any other
 * path (project cascade aside, where the videos die with the project anyway): videos survive with
 * a null template.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropForeign(['template_id']);
        });

        Schema::table('videos', function (Blueprint $table) {
            $table->foreignId('template_id')->nullable()->change();
            $table->foreign('template_id')->references('id')->on('templates')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropForeign(['template_id']);
        });

        Schema::table('videos', function (Blueprint $table) {
            $table->foreignId('template_id')->nullable(false)->change();
            $table->foreign('template_id')->references('id')->on('templates')->cascadeOnDelete();
        });
    }
};
