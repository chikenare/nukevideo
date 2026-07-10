<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('output_stream', function (Blueprint $table) {
            $table->foreignId('output_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stream_id')->constrained()->cascadeOnDelete();
            $table->primary(['output_id', 'stream_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('output_stream');
    }
};
