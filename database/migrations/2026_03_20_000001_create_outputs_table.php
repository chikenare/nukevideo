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
        // An output is one CMAF package (a codec/rendition group); the streaming formats it can
        // serve (HLS/DASH) are derived from its streams' codecs, not stored. See Output::formats().
        Schema::create('outputs', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->string('status', 50)->default(VideoStatus::PENDING->value);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outputs');
    }
};
