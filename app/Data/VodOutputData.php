<?php

namespace App\Data;

use App\Models\Output;
use Spatie\LaravelData\Data;

class VodOutputData extends Data
{
    public function __construct(
        public string $ulid,
        /** @var list<string> */
        public array $formats,
        public string $url,
        public string $subtitlesUrl,
        public string $thumbnailUrl,
        public string $storyboardUrl,
    ) {}

    public static function fromOutput(Output $output, string $vodUrl, string $videoUlid): self
    {
        return new self(
            ulid: $output->ulid,
            formats: $output->formats(),
            url: $vodUrl,
            subtitlesUrl: url("api/videos/{$videoUlid}/subtitles"),
            thumbnailUrl: url("api/videos/{$videoUlid}/thumbnail.jpg"),
            storyboardUrl: url("api/videos/{$videoUlid}/storyboard.vtt"),
        );
    }
}
