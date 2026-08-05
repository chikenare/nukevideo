<?php

namespace App\Services;

use App\Models\Stream;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Proves a rendition's resolved parameters actually encode, over one second of the source. Sets an
 * encoder refuses — a quality target pinned next to a bitrate, a VBV a codec only takes in another
 * mode — abort having written nothing, so without this they surface as every chunk of the video
 * failing after the whole fan-out. Here the video fails at once, in the encoder's own words.
 */
class RenditionPreflight
{
    // Enough for an encoder to accept or refuse a parameter set (~0.2-0.7s wall time).
    private const SECONDS = 1.0;

    public function __construct(
        private Stream $stream,
    ) {}

    public function assert(string $sourcePath, ?Closure $tick = null): void
    {
        // A rendition whose hardware this node lacks can't be told apart from one whose parameters
        // are wrong — both just fail here. Its own node finds out at the first chunk.
        if (! (new ChunkTranscodeService($this->stream))->runsOnThisNode()) {
            return;
        }

        $result = (new SampleEncode($this->stream, $sourcePath))->run(0.0, self::SECONDS, $tick);

        if ($result->wrote()) {
            return;
        }

        Log::error('Rendition preflight failed', [
            'video' => $this->stream->video_id,
            'stream' => $this->stream->id,
            'command' => $result->command,
        ]);

        throw new RuntimeException(
            "Rendition {$this->stream->width}x{$this->stream->height} cannot be encoded with these parameters: "
            .Str::limit($result->error, 400)
        );
    }
}
