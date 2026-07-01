<?php

namespace App\Http\Controllers;

use App\DTOs\StorageWebhookData;
use App\Jobs\OnVideoUploaded;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VideoWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $data = StorageWebhookData::fromRequest($request);

        if (! $data) {
            Log::info('Webhook received with unrecognized payload');

            return response()->json(['message' => 'ok']);
        }

        Log::info('Webhook received', ['event' => $data->eventName, 'records' => count($data->records)]);

        if (in_array($data->eventName, ['s3:ObjectCreated:CompleteMultipartUpload', 's3:ObjectCreated:Put'])) {
            foreach ($data->records as $record) {
                $key = data_get($record, 's3.object.key');

                if (! is_string($key) || $key === '') {
                    Log::warning('Webhook record missing object key; skipped', ['record' => $record]);

                    continue;
                }

                $key = urldecode($key);
                if (! str_contains($key, 'tmp-videos')) {
                    Log::debug('Webhook skipped non-tmp key', ['key' => $key]);

                    continue;
                }

                $size = (int) data_get($record, 's3.object.size', 0);

                Log::info('Dispatching OnVideoUploaded', ['key' => $key, 'size' => $size]);
                OnVideoUploaded::dispatch($key, $size)->onQueue('video-processing');
            }
        }

        return response()->json(['success' => true]);
    }
}
