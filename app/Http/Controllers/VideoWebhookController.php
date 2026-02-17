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
        $records = $request->json('Records');

        foreach ($records as $record) {
            $object = $record['s3']['object'];
            OnVideoUploaded::dispatch($object);
        }

        return response()->noContent();
    }
}
