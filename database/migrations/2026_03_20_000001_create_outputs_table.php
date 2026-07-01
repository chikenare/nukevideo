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
        // An output is one CMAF package (a codec/rendition group). The streaming formats it serves
        // (HLS/DASH) are frozen into `packaged_formats` once packaging finishes, rather than derived
        // live from its streams' codecs on every read — a later stream deletion edits manifests in
        // place but never deletes the file, so a live recomputation could drift from what's actually
        // servable on S3. See Output::formats()/recordFormats().
        Schema::create('outputs', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->string('status', 50)->default(VideoStatus::PENDING->value);
            $table->json('packaged_formats')->nullable();
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
