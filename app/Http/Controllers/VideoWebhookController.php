<?php

namespace App\Http\Controllers;

use App\DTOs\StorageWebhookData;
use App\Jobs\OnVideoUploaded;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VideoWebhookController extends Controller
{
    /**
     * Handle the incoming storage webhook.
     */
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

                $object = $record['s3']['object'];
                $key = urldecode($object['key']);
                $hasTmpKey = str_contains($key, 'tmp-videos');
                if (! $hasTmpKey) {
                    Log::debug('Webhook skipped non-tmp key', ['key' => $key]);

                    continue;
                }
                Log::info('Dispatching OnVideoUploaded', ['key' => $key, 'size' => $object['size']]);
                OnVideoUploaded::dispatch($key, (int) $object['size']);
            }
        }

        return response()->json(['success' => true]);
    }
}
