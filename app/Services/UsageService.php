<?php

namespace App\Services;

use ClickHouseDB\Client;
use Illuminate\Support\Facades\Log;

class UsageService
{
    /**
     * `$projectId` is not optional in practice, only in signature: upload volume and encoding
     * seconds carry no `video_ulid`, so without it two projects of the same account collapse into
     * one row on merge and the split is lost for good. 0 means "no project", which is what a row
     * written before the column existed reads as.
     */
    public static function record(int $userId, string $metric, float $value, string $externalUserId = '', int $projectId = 0): void
    {
        try {
            // The container binding already decided http vs https ({@see AppServiceProvider}).
            app(Client::class)
                ->insert('usage', [[
                    'user_id' => $userId,
                    'project_id' => $projectId,
                    'metric' => $metric,
                    'external_user_id' => $externalUserId,
                    'value' => $value,
                    'date' => now()->format('Y-m-d'),
                ]], ['user_id', 'project_id', 'metric', 'external_user_id', 'value', 'date']);
        } catch (\Throwable $e) {
            Log::warning("Failed to record usage ({$metric}) for user {$userId}: {$e->getMessage()}");
        }
    }
}
