<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('nodes', 'accel')) {
            return;
        }

        Schema::table('nodes', function (Blueprint $table) {
            // null = CPU-only worker; 'intel'/'nvidia' = has that GPU (see App\Enums\NodeAccel)
            $table->string('accel', 10)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn('accel');
        });
    }
};
