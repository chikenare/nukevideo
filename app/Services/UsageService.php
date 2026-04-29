<?php

namespace App\Services;

use ClickHouseDB\Client;
use Illuminate\Support\Facades\Log;

class UsageService
{
    public static function record(int $userId, string $metric, float $value, string $externalUserId = ''): void
    {
        try {
            app(Client::class)->insert('usage', [[
                'user_id' => $userId,
                'metric' => $metric,
                'external_user_id' => $externalUserId,
                'value' => $value,
                'date' => now()->format('Y-m-d'),
            ]], ['user_id', 'metric', 'external_user_id', 'value', 'date']);
        } catch (\Throwable $e) {
            Log::warning("Failed to record usage ({$metric}) for user {$userId}: {$e->getMessage()}");
        }
    }
}
