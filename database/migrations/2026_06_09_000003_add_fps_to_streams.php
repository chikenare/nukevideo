<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('streams', function (Blueprint $table) {
            $table->decimal('fps', 8, 2)->nullable()->after('progress');
        });
    }

    public function down(): void
    {
        Schema::table('streams', function (Blueprint $table) {
            $table->dropColumn('fps');
        });
    }
};
