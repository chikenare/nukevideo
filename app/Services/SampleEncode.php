<?php

namespace App\Services;

use App\Models\Stream;
use Closure;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Encodes a slice of the source with a rendition's REAL chunk command. What the media engine makes
 * of a parameter set is the whole question both callers ask ({@see QualityBitrateProbe} what it
 * costs, {@see RenditionPreflight} whether it runs at all), so a hand-rolled variant would answer a
 * different one. The sample file never outlives the call.
 */
class SampleEncode
{
    /** Long enough for a bitrate to mean something, short enough to spend on every rendition. */
    public const SECONDS = 20;

    /** Under this there is no middle of the runtime to sample. */
    public const MIN_DURATION = 120;

    /** Per sample; a 20s encode never legitimately needs more. */
    public const TIMEOUT = 300;

    public function __construct(
        private Stream $stream,
        private string $sourcePath,
    ) {}

    /**
     * Window starts spread across the middle of the runtime, so a sample is representative footage
     * rather than the credits.
     *
     * @return list<float>
     */
    public static function windows(float $duration): array
    {
        $count = (int) min(4, max(3, ceil($duration / 1800)));

        return array_map(
            fn (int $i) => round(min($duration * (0.08 + 0.84 * $i / ($count - 1)), $duration - self::SECONDS), 3),
            range(0, $count - 1),
        );
    }

    public function run(float $start, float $seconds = self::SECONDS, ?Closure $tick = null): SampleResult
    {
        $format = (new ChunkTranscodeService($this->stream))->outputFormat();
        $path = sprintf('%s/sample_%s_%d.%s', dirname($this->sourcePath), $this->stream->id, (int) round($start * 1000), $format);

        $command = EncodeCommandBuilder::build(
            new Collection([$this->stream]),
            $this->sourcePath,
            [$this->stream->id => $path],
            $start,
            $start + $seconds,
        );

        // Wall time is measured here rather than by the callers: this is the one place the real
        // chunk command runs against real footage before the fan-out commits to a window length.
        $startedAt = microtime(true);

        try {
            $result = Process::timeout(self::TIMEOUT)->run($command, fn () => $tick ? $tick() : null);

            return new SampleResult(
                $command,
                $result->successful(),
                (int) (@filesize($path) ?: 0),
                trim($result->errorOutput() ?: $result->output()),
                microtime(true) - $startedAt,
            );
        } catch (ProcessTimedOutException) {
            Log::warning('Sample encode timed out', ['stream' => $this->stream->id, 'start' => $start]);

            return new SampleResult($command, false, 0, 'Sample encode timed out', microtime(true) - $startedAt);
        } finally {
            @unlink($path);
        }
    }
}
