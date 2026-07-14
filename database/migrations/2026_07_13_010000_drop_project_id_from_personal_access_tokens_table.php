<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Project keys hang off the polymorphic `tokenable`, so the column is dead weight. */
    public function up(): void
    {
        if (! Schema::hasColumn('personal_access_tokens', 'project_id')) {
            return;
        }

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->dropForeign(['project_id']);
            }

            $table->dropColumn('project_id');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignId('project_id')
                ->nullable()
                ->after('tokenable_id')
                ->constrained()
                ->cascadeOnDelete();
        });
    }
};
