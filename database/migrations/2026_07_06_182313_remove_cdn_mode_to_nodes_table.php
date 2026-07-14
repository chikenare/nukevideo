<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('nodes', 'cdn_mode')) {
            return;
        }

        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn('cdn_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->boolean('cdn_mode')->default(false);
        });
    }
};
