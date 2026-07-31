<?php

namespace App\Services;

use App\Models\Stream;
use Closure;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Measures what a template's quality mode costs on THIS source: test-encodes a few windows with the
 * template's own settings, on the same hardware path the chunks will use, and records the mean
 * bitrate in `meta.quality_bitrate`. It decides nothing — {@see ChunkTranscodeService} reads the
 * number when it resolves the rendition's ceiling, and only asks for one where the encoder honours
 * no VBV of its own ({@see ChunkTranscodeService::needsQualityBitrateProbe}). ~7s per rendition on
 * an Arc B580; a failed probe leaves the number unset, which reads as "unmeasured" and changes
 * nothing.
 */
class QualityBitrateProbe
{
    private const SAMPLE_SECONDS = 20;

    private const MIN_DURATION = 120;

    // Per sample; a 20s encode never legitimately needs more.
    private const PROCESS_TIMEOUT = 300;

    public function __construct(
        private Stream $stream,
    ) {}

    public function measure(string $sourcePath, float $duration, ?Closure $tick = null): void
    {
        if ($duration < self::MIN_DURATION || isset($this->stream->meta['quality_bitrate'])) {
            return;
        }

        $service = new ChunkTranscodeService($this->stream);

        if (! $service->needsQualityBitrateProbe()) {
            return;
        }

        // Landed on a node without this encoder's hardware: every sample would fail for want of a
        // device, which is not an answer about the template. Leave it unmeasured (uncapped) — the
        // job hops to a capable node before this ({@see \App\Jobs\PrepareVideoJob}).
        if (! $service->runsOnThisNode()) {
            Log::warning('Quality-mode bitrate not measured: node has no matching accelerator', [
                'stream' => $this->stream->id,
                'node_accel' => config('ffmpeg.node_accel'),
            ]);

            return;
        }

        try {
            $bitrate = $this->sampleBitrate($service, $sourcePath, $duration, $tick ?? fn () => null);

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
    private function sampleBitrate(ChunkTranscodeService $service, string $sourcePath, float $duration, Closure $tick): int
    {
        $bytes = 0;
        $seconds = 0;

        foreach (PerTitleCrfService::sampleWindows($duration) as $index => $start) {
            $sample = dirname($sourcePath)."/qualitybitrate_{$this->stream->id}_{$index}.".$service->outputFormat();

            // The real chunk command, hardware path included: what the media engine makes of these
            // settings is the whole measurement, so a hand-rolled variant would answer a different
            // question — and the software fallback a different one again.
            $command = EncodeCommandBuilder::build(
                new Collection([$this->stream]),
                $sourcePath,
                [$this->stream->id => $sample],
                $start,
                $start + self::SAMPLE_SECONDS,
            );

            try {
                $result = Process::timeout(self::PROCESS_TIMEOUT)->run($command, fn () => $tick());

                if ($result->successful() && ($size = @filesize($sample)) > 0) {
                    $bytes += $size;
                    $seconds += self::SAMPLE_SECONDS;
                }
            } catch (ProcessTimedOutException) {
                Log::warning('Quality-mode sample timed out; dropping its window', ['stream' => $this->stream->id]);
            } finally {
                @unlink($sample);
            }
        }

        if ($seconds === 0) {
            throw new RuntimeException('No sample window encoded');
        }

        return (int) round($bytes * 8 / $seconds);
    }
}
