<?php

namespace App\Services;

use App\Models\Stream;
use Closure;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Per-title CRF: test-encode short windows of the source at two CRF anchors, measure VMAF
 * against the source and interpolate the CRF that hits the template's `target_vmaf`. The
 * chosen CRF replaces the template CRF on the stream before chunks fan out; any probe
 * failure keeps the template CRF — per-title never blocks a video.
 */
class PerTitleCrfService
{
    private const SAMPLE_SECONDS = 20;

    private const ANCHOR_STEP = 8;

    // The probe corrects the template CRF, it doesn't replace template intent — bound the swing.
    private const MAX_DECREASE = 4;

    private const MAX_INCREASE = 12;

    private const MIN_DURATION = 120;

    // Per probe process; a 20s sample encode/score never legitimately needs more.
    private const PROCESS_TIMEOUT = 300;

    // The probe runs inside ONE worker slot, so it may not spend the whole node. Keep it to a
    // couple of samples at a time; the chunk encoders own the rest of the CPU.
    private const MAX_CONCURRENCY = 2;

    public function __construct(
        private Stream $stream,
    ) {}

    public function apply(string $sourcePath, float $duration, ?Closure $tick = null): void
    {
        [$crfKey, $maxCrf] = $this->crfParameter();

        if (! $this->shouldProbe($crfKey, $duration)) {
            return;
        }

        try {
            $this->resolve($crfKey, $maxCrf, $sourcePath, $duration, $tick ?? fn () => null);
        } catch (Throwable $e) {
            Log::warning('Per-title probe failed; keeping template CRF', [
                'stream' => $this->stream->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function resolve(string $crfKey, int $maxCrf, string $sourcePath, float $duration, Closure $tick): void
    {
        $params = $this->stream->input_params;
        $base = (int) $params[$crfKey];
        $target = (int) $params['target_vmaf'];
        $windows = self::sampleWindows($duration);

        $anchorCrfs = array_values(array_unique([$base, min($base + self::ANCHOR_STEP, $maxCrf)]));

        // Base already at the codec ceiling: nowhere to interpolate, skip the probe entirely.
        if (count($anchorCrfs) < 2) {
            Log::info('Per-title skipped: base CRF at codec ceiling', ['stream' => $this->stream->id]);

            return;
        }

        $anchors = $this->measureAnchors($anchorCrfs, $crfKey, $windows, $sourcePath, $tick);
        $chosen = self::chooseCrf($anchors, $target, $maxCrf);

        $params[$crfKey] = $chosen;
        $meta = $this->stream->meta ?? [];
        $meta['per_title'] = [
            'target_vmaf' => $target,
            'base_crf' => $base,
            'chosen_crf' => $chosen,
            'anchors' => array_map(fn (float $score) => round($score, 2), $anchors),
            'windows' => count($windows),
        ];

        $this->stream->update(['input_params' => $params, 'meta' => $meta]);

        Log::info('Per-title CRF resolved', ['stream' => $this->stream->id] + $meta['per_title']);
    }

    /**
     * Interpolate the CRF hitting `$target` from two measured anchors (crf => vmaf). VMAF is
     * near-linear in CRF over a one-step span; a flat curve means the probe saturated (or the
     * source is trivial), so fall back to whichever anchor still meets the target.
     *
     * @param  array<int, float>  $anchors
     */
    public static function chooseCrf(array $anchors, int $target, int $maxCrf): int
    {
        ksort($anchors);
        [$lowCrf, $highCrf] = array_keys($anchors);
        [$lowScore, $highScore] = array_values($anchors);

        $slope = ($highScore - $lowScore) / max(1, $highCrf - $lowCrf);

        $chosen = $slope > -0.05
            ? ($highScore >= $target ? $highCrf : $lowCrf)
            : $lowCrf + ($target - $lowScore) / $slope;

        $chosen = max($lowCrf - self::MAX_DECREASE, min($lowCrf + self::MAX_INCREASE, $chosen));

        return (int) max(1, min($maxCrf, round($chosen)));
    }

    /** @return list<float> window start offsets, spread across the middle of the runtime */
    public static function sampleWindows(float $duration): array
    {
        $count = (int) min(4, max(3, ceil($duration / 1800)));

        return array_map(
            fn (int $i) => round(min($duration * (0.08 + 0.84 * $i / ($count - 1)), $duration - self::SAMPLE_SECONDS), 3),
            range(0, $count - 1),
        );
    }

    /**
     * Encode every (anchor × window) sample in ONE pool, then VMAF-score them all in a second —
     * each probe encode is short and thread-capped, and pooling both anchors together halves the
     * wall time this adds to PrepareVideoJob's fixed budget. Windows pool into a harmonic mean
     * so one bad window drags its anchor down more than a plain average would.
     *
     * @param  list<int>  $anchorCrfs
     * @param  list<float>  $windows
     * @return array<int, float> crf => pooled vmaf score
     */
    private function measureAnchors(array $anchorCrfs, string $crfKey, array $windows, string $sourcePath, Closure $tick): array
    {
        $jobs = [];
        foreach ($anchorCrfs as $crf) {
            foreach ($windows as $i => $start) {
                $jobs[] = [
                    'crf' => $crf,
                    'start' => $start,
                    'sample' => dirname($sourcePath)."/pertitle_{$this->stream->id}_{$crf}_{$i}.mp4",
                ];
            }
        }

        try {
            $encodes = $this->runPool(array_map(
                fn (array $job) => $this->encodeSampleCommand($job['crf'], $crfKey, $job['start'], $sourcePath, $job['sample']),
                $jobs,
            ), $tick);

            // Only score what actually encoded; a lost sample costs its window, not the probe.
            $encoded = array_keys(array_filter($encodes, fn (?string $output) => $output !== null));

            $outputs = $this->runPool(array_map(
                fn (int $index) => $this->vmafCommand($jobs[$index]['start'], $sourcePath, $jobs[$index]['sample']),
                $encoded,
            ), $tick);
        } finally {
            array_map(fn (array $job) => @unlink($job['sample']), $jobs);
        }

        $scores = [];
        foreach ($outputs as $position => $output) {
            $score = $output === null ? null : self::parseVmafScore($output);

            if ($score !== null) {
                $scores[$jobs[$encoded[$position]]['crf']][] = $score;
            }
        }

        // Interpolating between anchors needs both of them; one that lost every window is no anchor.
        foreach ($anchorCrfs as $crf) {
            if (empty($scores[$crf])) {
                throw new RuntimeException("No sample window scored for CRF {$crf}");
            }
        }

        return array_map(
            fn (array $windowScores) => count($windowScores) / array_sum(array_map(fn (float $s) => 1 / max($s, 1.0), $windowScores)),
            $scores,
        );
    }

    private function encodeSampleCommand(int $crf, string $crfKey, float $start, string $sourcePath, string $samplePath): string
    {
        // Replicated stream so the anchor CRF renders through the exact same argument builder
        // (scale, GOP, *-params, maxrate clamp) the real chunk encode will use.
        $probe = $this->stream->replicate();
        $probe->input_params = [$crfKey => $crf] + ($probe->input_params ?? []);
        $service = new ChunkTranscodeService($probe);

        return sprintf(
            'ffmpeg -hide_banner -y -ss %.3f -t %d -i %s -fps_mode passthrough %s -f %s %s',
            $start,
            self::SAMPLE_SECONDS,
            escapeshellarg($sourcePath),
            $service->buildVideoArguments(windowed: true),
            $service->outputFormat(),
            escapeshellarg($samplePath),
        );
    }

    private function vmafCommand(float $start, string $sourcePath, string $samplePath): string
    {
        // Reference downscaled to the rendition's resolution with ffmpeg's default scaler — the
        // same one the sample encode used; a sharper kernel here would bias every score low.
        // Pair frames by index (settb=AVTB,setpts=N), not by timestamp: the source and the .mp4
        // sample carry different timebases (1/fps vs 1/1000), so libvmaf's default PTS framesync
        // mispairs them and every score reads ~35 points low.
        $filter = sprintf(
            '[%s]scale=%d:%d,settb=AVTB,setpts=N[ref];[1:v:0]settb=AVTB,setpts=N[dist];[dist][ref]libvmaf=n_threads=2',
            (new ChunkTranscodeService($this->stream))->mapTarget(),
            (int) $this->stream->width,
            (int) $this->stream->height,
        );

        return sprintf(
            'ffmpeg -hide_banner -ss %.3f -t %d -i %s -i %s -lavfi "%s" -f null -',
            $start,
            self::SAMPLE_SECONDS,
            escapeshellarg($sourcePath),
            escapeshellarg($samplePath),
            $filter,
        );
    }

    private static function parseVmafScore(string $output): ?float
    {
        return preg_match('/VMAF score: ([\d.]+)/', $output, $matches) ? (float) $matches[1] : null;
    }

    /**
     * Run the probe commands a few at a time. The pool used to launch every (anchor × window) at
     * once — 6-8 ffmpeg on top of the node's chunk encoders, which is what OOM-killed staging — and
     * it threw on the first failure, discarding every other measurement with it.
     *
     * @param  list<string>  $commands
     * @return list<?string> combined stdout+stderr per command, in input order; null when it failed
     */
    private function runPool(array $commands, Closure $tick): array
    {
        $outputs = [];

        foreach (array_chunk($commands, self::MAX_CONCURRENCY) as $batch) {
            try {
                $results = Process::pool(function (Pool $pool) use ($batch) {
                    foreach ($batch as $command) {
                        $pool->timeout(self::PROCESS_TIMEOUT)->command($command);
                    }
                })->start(fn () => $tick())->wait();

                foreach ($results->collect() as $result) {
                    $outputs[] = $result->successful() ? $result->output().$result->errorOutput() : null;
                }
            } catch (ProcessTimedOutException) {
                // The pool surfaces a timeout as a throw, so the batch's other results go with it.
                // Small batches keep that cheap: a stuck sample costs its window, nothing else.
                Log::warning('Per-title sample timed out; dropping its window', [
                    'stream' => $this->stream->id,
                ]);

                $outputs = array_pad($outputs, count($outputs) + count($batch), null);
            }
        }

        return $outputs;
    }

    private function shouldProbe(?string $crfKey, float $duration): bool
    {
        $params = $this->stream->input_params ?? [];

        if (! $crfKey || empty($params['target_vmaf']) || ! isset($params[$crfKey])) {
            return false;
        }

        // ABR mode picks bitrate explicitly; per-title only steers CRF. No copy-detection guard:
        // video chunks are always window-cut, so the copy fast-path never applies to them.
        if (! empty($params['constant_bitrate'])) {
            return false;
        }

        if ($duration < self::MIN_DURATION || ! $this->stream->width || ! $this->stream->height) {
            return false;
        }

        // Redelivery: a previous attempt already resolved this stream.
        return ! isset($this->stream->meta['per_title']);
    }

    /**
     * The CRF field and its ceiling for this stream's codec, from config/ffmpeg.php — the same
     * source of truth the panel and command builder use, so a new codec added there is picked
     * up here without a parallel hardcoded map.
     *
     * @return array{0: ?string, 1: int} [param key, max crf]
     */
    private function crfParameter(): array
    {
        $codec = data_get($this->stream->input_params, 'video_codec');

        foreach (config('ffmpeg.parameters') as $key => $config) {
            if (($config['template'] ?? null) === '-crf %s' && in_array($codec, $config['available_for'] ?? [], true)) {
                return [$key, (int) ($config['max'] ?? 51)];
            }
        }

        return [null, 51];
    }
}
