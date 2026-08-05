<?php

namespace App\Services;

use App\Models\Stream;
use Closure;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Measures what a template's quality mode costs on THIS source and records it in
 * `meta.quality_bitrate` (~7s per rendition). It decides nothing: {@see ChunkTranscodeService} reads
 * the number when it resolves the rendition's ceiling, and only asks for one where the encoder
 * honours no VBV of its own. A failed probe leaves it unset, which reads as "unmeasured".
 */
class QualityBitrateProbe
{
    public function __construct(
        private Stream $stream,
    ) {}

    public function measure(string $sourcePath, float $duration, ?Closure $tick = null): void
    {
        if ($duration < SampleEncode::MIN_DURATION || isset($this->stream->meta['quality_bitrate'])) {
            return;
        }

        $service = new ChunkTranscodeService($this->stream);

        if (! $service->needsQualityBitrateProbe()) {
            if ($service->encodesUncapped()) {
                Log::warning('Quality mode runs uncapped: the source states no bit rate', [
                    'stream' => $this->stream->id,
                    'codec' => $this->stream->input_params['video_codec'] ?? null,
                ]);
            }

            return;
        }

        // Landed on a node without this encoder's hardware: every sample would fail for want of a
        // device, which is no answer about the template. Unmeasured reads as uncapped, and
        // {@see \App\Jobs\PrepareVideoJob} hops to a capable node before it gets here.
        if (! $service->runsOnThisNode()) {
            Log::warning('Quality-mode bitrate not measured: node has no matching accelerator', [
                'stream' => $this->stream->id,
                'node_accel' => config('ffmpeg.node_accel'),
            ]);

            return;
        }

        try {
            $bitrate = $this->sampleBitrate($sourcePath, $duration, $tick);

            $this->stream->update(['meta' => [...$this->stream->meta ?? [], 'quality_bitrate' => $bitrate]]);

            Log::info('Quality-mode bitrate measured', [
                'stream' => $this->stream->id,
                'bitrate' => $bitrate,
                'cap' => $service->sourceBitrateCap(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Quality-mode bitrate probe failed', [
                'stream' => $this->stream->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Mean bitrate over windows spread across the runtime; a lost window costs itself, not the probe. */
    private function sampleBitrate(string $sourcePath, float $duration, ?Closure $tick): int
    {
        $sampler = new SampleEncode($this->stream, $sourcePath);
        $bytes = 0;
        $seconds = 0;

        foreach (SampleEncode::windows($duration) as $start) {
            $result = $sampler->run($start, SampleEncode::SECONDS, $tick);

            if ($result->wrote()) {
                $bytes += $result->bytes;
                $seconds += SampleEncode::SECONDS;
            }
        }

        if ($seconds === 0) {
            throw new RuntimeException('No sample window encoded');
        }

        return (int) round($bytes * 8 / $seconds);
    }
}
