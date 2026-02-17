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
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->nullable()->constrained('nodes')->nullOnDelete();

            $table->string('name');
            $table->decimal('duration', 10, 4);
            $table->string('aspect_ratio', 10);
            $table->string('output_format', 10)->default('hls');
            $table->string('thumbnail_path')->nullable();
            $table->enum('status', array_column(VideoStatus::cases(), 'value'))
                ->default(VideoStatus::PENDING->value);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
