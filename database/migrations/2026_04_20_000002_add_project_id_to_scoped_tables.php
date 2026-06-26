<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->foreignId('project_id')
                ->after('user_id')
                ->constrained()
                ->cascadeOnDelete();
        });

        Schema::table('templates', function (Blueprint $table) {
            $table->foreignId('project_id')
                ->after('user_id')
                ->constrained()
                ->cascadeOnDelete();
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignId('project_id')
                ->nullable()
                ->after('tokenable_id')
                ->constrained()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });

        Schema::table('templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });

        Schema::table('videos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });
    }
};
