<?php

namespace App\Http\Controllers;

use App\Jobs\OnVideoUploaded;
use Illuminate\Http\Request;

class VideoWebhookController extends Controller
{
    /**
     * Handle the incoming storage webhook.
     */
    public function handle(Request $request)
    {
        $event = $request->json('EventName');
        $hasTmpKey = str_contains($request->json('Key'), 'nukevideo/tmp-videos');

        if ($hasTmpKey && in_array($event, ['s3:ObjectCreated:CompleteMultipartUpload', 's3:ObjectCreated:Put'])) {
            $records = $request->json('Records');

            foreach ($records as $record) {
                $object = $record['s3']['object'];
                OnVideoUploaded::dispatch($object);
            }
        }

        return response()->noContent();
    }
}
