<?php

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * The answer to a batch mint: a link per track that could be served, and a line per track that
 * could not, so a caller can tell "this video has no 4K" from "your id was wrong".
 */
class VideoDownloadLinksData extends Data
{
    public function __construct(
        /** @var DownloadLinkData[] */
        public array $links,
        /** @var SkippedTrackData[] */
        public array $skipped,
    ) {}
}
