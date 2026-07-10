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
        Schema::create('streams', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('video_id')->constrained()->cascadeOnDelete();

            // Unique: the uploaded source's storage key is the original stream's path,
            // so this enforces "ingest each upload exactly once" at the DB level.
            $table->string('path')->unique();
            $table->string('name')->nullable();
            $table->enum('type', ['original', 'video', 'audio', 'subtitle']);
            $table->unsignedBigInteger('size');

            $table->json('input_params')->nullable();

            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('language')->nullable();
            $table->unsignedTinyInteger('channels')->nullable();

            $table->json('meta');

            $table->text('error_log')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('streams');
    }
};
