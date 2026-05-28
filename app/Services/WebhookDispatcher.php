<?php

namespace App\Services;

use App\Data\VideoWebhookData;
use App\Jobs\DispatchWebhookJob;
use App\Models\Video;

class WebhookDispatcher
{
    public static function forVideo(string $event, Video $video): void
    {
        $project = $video->project;

        if (! $project) {
            return;
        }

        $settings = $project->settings ?? [];
        $url = $settings['webhook_url'] ?? null;

        if (! $url) {
            return;
        }

        $payload = [
            'event' => $event,
            'timestamp' => now()->timestamp,
            'data' => VideoWebhookData::fromModel($video),
        ];

        DispatchWebhookJob::dispatch(
            $url,
            $settings['webhook_secret'] ?? null,
            $payload,
        )->afterCommit();
    }
}
