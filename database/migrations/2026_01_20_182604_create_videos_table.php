<?php

use App\Enums\VideoStatus;
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
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('node_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->decimal('duration', 10, 4);
            $table->string('aspect_ratio', 10);
            $table->enum('status', array_column(VideoStatus::cases(), 'value'))
                ->default(VideoStatus::PENDING->value);

            // Liveness signal refreshed by every processing stage; a stale value lets
            // the reaper recover a video whose worker/node died mid-processing.
            $table->timestamp('last_heartbeat_at')->nullable();

            $table->string('external_user_id')->nullable();
            $table->string('external_resource_id')->nullable();
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
