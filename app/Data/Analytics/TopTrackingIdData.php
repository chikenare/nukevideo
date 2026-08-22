<?php

namespace App\Data\Analytics;

use App\Data\Stream\DownloadStreamData;
use Spatie\LaravelData\Data;

class TopTrackingIdData extends Data
{
    public function __construct(
        /**
         * The `tid` a download link carried ({@see DownloadStreamData}). Empty
         * for traffic that carried none — playback, and downloads minted without an id — which is
         * reported rather than hidden so the breakdown adds up to the total bandwidth.
         */
        public string $tid,
        public float $bytes,
        public int $videos,
        public int $uniqueIps,
    ) {}
}
