<?php

namespace App\Data;

use App\Models\Output;
use App\Models\Video;
use Spatie\LaravelData\Data;

class VodOutputData extends Data
{
    public function __construct(
        // public string $ulid,
        // /** @var list<string> */
        // public array $formats,
        public string $url,
        public string $thumbnailUrl,
        public string $storyboardUrl,
    ) {}

    public static function fromOutput(Output $output, string $vodUrl, string $videoUlid): self
    {
        return new self(
            // ulid: $output->ulid,
            // formats: $output->formats(),
            url: $vodUrl,
            thumbnailUrl: url('api/videos/'.Video::assetPath($videoUlid, Video::THUMBNAIL_FILENAME)),
            storyboardUrl: url('api/videos/'.Video::assetPath($videoUlid, Video::STORYBOARD_VTT_FILENAME)),
        );
    }
}
