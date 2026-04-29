<?php

namespace App\DTOs;

use Illuminate\Http\Request;

class StorageWebhookData
{
    /**
     * @param  array<int, array{
     *     eventName: string,
     *     s3: array{
     *         bucket: array{name: string},
     *         object: array{key: string, size: int, eTag: string, contentType: string}
     *     }
     * }>  $records
     */
    public function __construct(
        public readonly string $eventName,
        public readonly array $records,
    ) {}

    public static function fromRequest(Request $request): ?self
    {
        $records = $request->json('Records');

        if (is_array($records) && ! empty($records)) {
            return new self(
                eventName: $request->json('EventName') ?? $records[0]['eventName'] ?? throw new \InvalidArgumentException('Missing EventName'),
                records: $records,
            );
        }

        /** @var array<int, array{eventName: string, s3: array{bucket: array{name: string}, object: array{key: string, size?: int}}}> $events */
        $events = $request->json()->all();

        if (! isset($events[0]['eventName'])) {
            return null;
        }

        return new self(
            eventName: $events[0]['eventName'],
            records: $events,
        );
    }
}
