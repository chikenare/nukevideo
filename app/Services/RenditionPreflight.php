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
    use Concerns\RecordsEncodeRate;

    // Enough for an encoder to accept or refuse a parameter set (~0.2-0.7s wall time).
    private const SECONDS = 1.0;

    /**
     * Where in the source to take that second from. The opening is the cheapest second of most
     * files — a title card, a fade from black — and for a rendition in ABR mode this is the ONLY
     * encode anything measures: {@see PerTitleCrfService} and {@see QualityBitrateProbe} both skip
     * a variant that pins `constant_bitrate`. Sampling the middle costs one seek and answers the
     * acceptance question exactly as well, off footage the rest of the video looks like.
     */
    private const POSITION = 0.5;

    public function __construct(
        private Stream $stream,
    ) {}

    public function assert(string $sourcePath, float $duration = 0.0, ?Closure $tick = null): void
    {
        // A rendition whose hardware this node lacks can't be told apart from one whose parameters
        // are wrong — both just fail here. Its own node finds out at the first chunk.
        if (! (new ChunkTranscodeService($this->stream))->runsOnThisNode()) {
            return;
        }

        $result = (new SampleEncode($this->stream, $sourcePath))->run($this->sampleStart($duration), self::SECONDS, $tick);

        if ($result->wrote()) {
            $this->recordEncodeRate($this->stream, $result->wallSeconds, self::SECONDS);

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

    /**
     * Falls back to the opening whenever the middle cannot be trusted: a duration the caller could
     * not supply, or a clip too short to hold the sample anywhere but at its start. The check this
     * runs must never be the thing that fails a video, so an unknown duration keeps the behaviour
     * that has always worked rather than seeking somewhere that might not exist.
     */
    private function sampleStart(float $duration): float
    {
        $latest = $duration - self::SECONDS;

        return $latest > 0.0 ? round(min($duration * self::POSITION, $latest), 3) : 0.0;
    }
}
