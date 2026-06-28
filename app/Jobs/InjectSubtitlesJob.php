<?php

namespace App\Jobs;

use App\Models\Video;
use App\Services\ManifestEditor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Stitches the video's sidecar WebVTT subtitles into its CMAF manifests on S3 once packaging has
 * completed (the manifests and VTTs are already synced). Kept out of {@see PackageVideoJob} so a
 * subtitle hiccup can't fail or delay the packaging that makes the video servable; idempotent, so
 * a retry just re-applies the same edits.
 */
class InjectSubtitlesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [30, 120];

    public function __construct(
        public int $videoId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->videoId;
    }

    public function handle(ManifestEditor $editor): void
    {
        $video = Video::with('streams')->find($this->videoId);

        if (! $video) {
            return;
        }

        $editor->injectSubtitles($video);
    }
}
