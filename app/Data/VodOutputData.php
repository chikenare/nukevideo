<?php

namespace App\Data;

use App\Models\Output;
use App\Models\Video;
use App\Services\Cdn\AssetUrlResolver;
use App\Services\Cdn\SignedLink;
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

    public static function fromOutput(Output $output, SignedLink $link, string $videoUlid): self
    {
        $assets = app(AssetUrlResolver::class);

        return new self(
            // ulid: $output->ulid,
            // formats: $output->formats(),
            url: $link->url,
            thumbnailUrl: $assets->for($videoUlid, Video::THUMBNAIL_FILENAME),
            storyboardUrl: $assets->for($videoUlid, Video::STORYBOARD_VTT_FILENAME),
        );
    }
}
