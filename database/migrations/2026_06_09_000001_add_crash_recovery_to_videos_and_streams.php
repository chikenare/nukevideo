<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            // Liveness signal refreshed by every processing stage. The reaper uses
            // a stale value to detect a worker/node that died mid-processing and
            // recover the video instead of leaving it stuck until retry_after (~6h).
            $table->timestamp('last_heartbeat_at')->nullable()->after('status');

            // How many times the reaper has re-queued this video, so it eventually
            // gives up instead of looping forever. Also used as the run discriminator
            // that lets a superseded chain's failure handler know it no longer owns
            // the video (see VideoDispatchService::dispatch onFail guard).
            $table->unsignedTinyInteger('dispatch_attempts')->default(0)->after('last_heartbeat_at');
        });

        // Idempotent ingestion: the uploaded source's storage key is the original
        // stream's path. A unique index makes "ingest this upload exactly once"
        // enforceable at the DB level, so an at-least-once webhook or a job retry
        // can never create a second Video for the same upload.
        Schema::table('streams', function (Blueprint $table) {
            $table->unique('path');
        });
    }

    public function down(): void
    {
        Schema::table('streams', function (Blueprint $table) {
            $table->dropUnique(['path']);
        });

        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn(['last_heartbeat_at', 'dispatch_attempts']);
        });
    }
};
