<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class DispatchWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 900];

    public int $timeout = 10;

    public function __construct(
        public readonly string $url,
        public readonly ?string $secret,
        public readonly array $payload,
    ) {}

    public function handle(): void
    {
        $request = Http::timeout(5)
            ->acceptJson()
            ->asJson();

        if ($this->secret) {
            $request = $request->withToken($this->secret);
        }

        $response = $request->post($this->url, $this->payload);

        if ($response->failed()) {
            throw new RuntimeException(
                "Webhook returned status {$response->status()}"
            );
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('Webhook dispatch failed permanently', [
            'url' => $this->url,
            'event' => $this->payload['event'] ?? 'unknown',
            'error' => $e->getMessage(),
        ]);
    }
}
