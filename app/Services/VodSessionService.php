<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class VodSessionService
{
    // Static one-day lifetime — comfortably outlasts any single playback.
    private const SESSION_TTL = 86400;

    public static function cacheKey(string $sessionId): string
    {
        return "vod:session:{$sessionId}";
    }

    /**
     * Stash the session's business metadata in Redis so the bandwidth ingest
     * pipeline can join it back to raw byte counts without any lookup table in
     * ClickHouse.
     */
    public static function create(
        string $sessionId,
        int $userId,
        string $videoUlid,
        string $outputUlid,
        string $externalResourceId,
        string $externalUserId = '',
    ): void {
        Cache::put(
            self::cacheKey($sessionId),
            [
                'user_id' => $userId,
                'video_ulid' => $videoUlid,
                'output_ulid' => $outputUlid,
                'external_resource_id' => $externalResourceId,
                'external_user_id' => $externalUserId,
            ],
            self::SESSION_TTL,
        );
    }

    /** @return array<string, mixed>|null */
    public static function resolve(string $sessionId): ?array
    {
        return Cache::get(self::cacheKey($sessionId));
    }
}
