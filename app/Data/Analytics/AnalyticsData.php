<?php

namespace App\Data\Analytics;

use Spatie\LaravelData\Data;

class AnalyticsData extends Data
{
    public function __construct(
        /** @var AnalyticsCardData[] */
        public array $cards,
        /** @var BandwidthPointData[] */
        public array $bandwidthOverTime,
        /** @var TopIpData[] */
        public array $topIps,
        /** @var TopVideoData[] */
        public array $topVideos,
        /** @var TopExternalUserData[] */
        public array $topExternalUsers,
        /** @var BandwidthByVideoData[] */
        public array $bandwidthByVideo,
        /** @var EncodingPointData[] */
        public array $encodingOverTime,
    ) {}
}
