<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('nodes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('container_id')->nullable();
            $table->string('host')->nullable();
            $table->string('type')->default('worker');
            $table->string('location');
            $table->boolean('is_active')->default(true);
            $table->string('status')->default('unknown');
            $table->string('uptime')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nodes');
    }
};
