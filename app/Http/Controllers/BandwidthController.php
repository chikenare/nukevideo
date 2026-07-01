<?php

namespace App\Http\Controllers;

use App\Jobs\IngestBandwidthJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BandwidthController extends Controller
{
    /**
     * Receives a batch of aggregated bandwidth events from Vector and hands it to
     * a queue job for enrichment + ClickHouse insert, so the request returns fast
     * and never blocks Vector on ClickHouse latency.
     */
    public function ingest(Request $request): JsonResponse
    {
        $events = $request->json()->all();

        if (! is_array($events) || $events === []) {
            return response()->json(['accepted' => 0]);
        }

        // Vector may send a single event as an object rather than an array.
        if (array_is_list($events) === false) {
            $events = [$events];
        }

        IngestBandwidthJob::dispatch($events);

        return response()->json(['accepted' => count($events)]);
    }
}
