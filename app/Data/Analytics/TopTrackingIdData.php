<?php

namespace App\Data\Analytics;

use App\Data\Stream\DownloadStreamData;
use Spatie\LaravelData\Data;

class TopTrackingIdData extends Data
{
    public function __construct(
        /**
         * The tracking id a link carried ({@see DownloadStreamData}). Empty
         * for traffic that carried none — links minted without an id — which is
         * reported rather than hidden so the breakdown adds up to the total bandwidth.
         */
        public string $trackingId,
        public float $bytes,
        public int $videos,
        public int $uniqueIps,
    ) {}
}
