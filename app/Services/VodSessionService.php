<?php

namespace App\Services;

use ClickHouseDB\Client;
use Illuminate\Support\Facades\Log;

class VodSessionService
{
    public static function create(
        string $sessionId,
        int $userId,
        string $videoUlid,
        string $outputUlid,
        string $externalResourceId,
    ): void {
        try {
            app(Client::class)->insert('sessions_active', [[
                'session_id' => $sessionId,
                'user_id' => $userId,
                'video_ulid' => $videoUlid,
                'output_ulid' => $outputUlid,
                'external_resource_id' => $externalResourceId,
                'created_at' => now()->format('Y-m-d H:i:s'),
            ]], ['session_id', 'user_id', 'video_ulid', 'output_ulid', 'external_resource_id', 'created_at']);
        } catch (\Throwable $e) {
            Log::warning("Failed to create VOD session {$sessionId}: {$e->getMessage()}");
        }
    }
}
