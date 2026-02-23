<?php

use App\Enums\VideoStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('streams', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();

            $table->foreignId('video_id')->constrained()->cascadeOnDelete();

            $table->string('path');
            $table->string('name')->nullable();
            $table->enum('type', ['original', 'download', 'video', 'audio', 'subtitle']);
            $table->unsignedBigInteger('size');

            $table->json('input_params')->nullable();

            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('language')->nullable();
            $table->unsignedTinyInteger('channels')->nullable();

            $table->json('meta');

            $table->enum('status', array_column(VideoStatus::cases(), 'value'))
                ->default(VideoStatus::PENDING->value);

            $table->unsignedTinyInteger('progress')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

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
