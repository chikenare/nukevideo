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
    use Concerns\RecordsEncodeRate;

    /** Wall time of each batch the last {@see runPool} ran, in batch order. */
    private array $poolBatchSeconds = [];

    private const ANCHOR_STEP = 8;

    // The probe corrects the template CRF, it doesn't replace template intent — bound the swing.
    private const MAX_DECREASE = 4;

    private const MAX_INCREASE = 12;

    /**
     * How far above the target a flat curve must sit to read as saturation. VMAF only stops
     * answering to CRF near its ceiling; a curve flat right at the target means the measurement
     * is not tracking CRF at all — a VBV starving both anchors did exactly that (26 → 94.24,
     * 34 → 94.19) and sent the rendition eight CRF steps up.
     */
    private const SATURATION_MARGIN = 3.0;

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
        $windows = SampleEncode::windows($duration);

        $anchorCrfs = array_values(array_unique([$base, min($base + self::ANCHOR_STEP, $maxCrf)]));

        // Base already at the codec ceiling: nowhere to interpolate, skip the probe entirely.
        if (count($anchorCrfs) < 2) {
            Log::info('Per-title skipped: base CRF at codec ceiling', ['stream' => $this->stream->id]);

            return;
        }

        [$anchors, $bitrates] = $this->measureAnchors($anchorCrfs, $crfKey, $windows, $sourcePath, $tick);
        $vmafCrf = self::chooseCrf($anchors, $target, $maxCrf);

        // VMAF alone will happily buy a noisy, already-compressed source's grain back at more bits
        // than the source itself spent (video 9059: 2.03 Mbps out of a 1.49 Mbps H.264). The
        // VBV only trims peaks now, so this is the one place the average answers to the source.
        $ceiling = (new ChunkTranscodeService($this->stream))->sourceAverageCeiling();
        $chosen = $ceiling === null ? $vmafCrf : self::capCrfToBitrate($bitrates, $vmafCrf, $ceiling, $maxCrf);
        $estimated = self::estimateBitrate($bitrates, $chosen);

        $params[$crfKey] = $chosen;
        $meta = $this->stream->meta ?? [];
        $meta['per_title'] = [
            'target_vmaf' => $target,
            'base_crf' => $base,
            'vmaf_crf' => $vmafCrf,
            'chosen_crf' => $chosen,
            'anchors' => array_map(fn (float $score) => round($score, 2), $anchors),
            'anchor_bitrates' => $bitrates,
            'bitrate_ceiling' => $ceiling,
            'estimated_bitrate' => $estimated === null ? null : (int) round($estimated),
            'windows' => count($windows),
        ];

        $this->stream->update(['input_params' => $params, 'meta' => $meta]);

        Log::info('Per-title CRF resolved', ['stream' => $this->stream->id] + $meta['per_title']);

        // Bounded by MAX_INCREASE or the codec ceiling before it reached the source's rate: the
        // rendition will outweigh its source, and nothing downstream stops it. Say so.
        if ($ceiling !== null && $estimated !== null && $estimated > $ceiling) {
            Log::warning('Per-title CRF cannot bring the rendition under its source bitrate', [
                'stream' => $this->stream->id,
                'chosen_crf' => $chosen,
                'estimated_bitrate' => (int) round($estimated),
                'bitrate_ceiling' => $ceiling,
            ]);
        }
    }

    /**
     * Interpolate the CRF hitting `$target` from two measured anchors (crf => vmaf). VMAF is
     * near-linear in CRF over a one-step span. A flat curve gives no slope to interpolate with:
     * take the top anchor only when both sit clearly above the target (a saturated probe or a
     * trivial source); anywhere else the measurement can't be trusted, so keep the template CRF.
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
            ? ($highScore >= $target + self::SATURATION_MARGIN ? $highCrf : $lowCrf)
            : $lowCrf + ($target - $lowScore) / $slope;

        // Upward, never past the top anchor: beyond it the line is a guess, and a guess that
        // overshoots costs visible quality (a -0.07 slope extrapolated 26 → 38). Downward, the
        // guess only spends bits, so it may reach MAX_DECREASE below the base.
        $chosen = max($lowCrf - self::MAX_DECREASE, min($highCrf, $lowCrf + self::MAX_INCREASE, $chosen));

        return (int) max(1, min($maxCrf, round($chosen)));
    }

    /**
     * Raise `$crf` until the anchors' bitrate curve puts it at or under `$ceiling` — never lower it:
     * this only ever takes bits away from what VMAF asked for. Bitrate is close to exponential in
     * CRF, so the curve is interpolated in log space, and unlike VMAF it extrapolates well past the
     * top anchor (it is what CRF is defined by), up to {@see MAX_INCREASE} over the base. A curve
     * that doesn't fall with CRF is a broken measurement, and keeps the VMAF choice.
     *
     * @param  array<int, int>  $bitrates  crf => measured bps
     */
    public static function capCrfToBitrate(array $bitrates, int $crf, int $ceiling, int $maxCrf): int
    {
        $curve = self::bitrateCurve($bitrates);

        if ($curve === null || $ceiling <= 0 || self::estimateBitrate($bitrates, $crf) <= $ceiling) {
            return $crf;
        }

        [$lowCrf, $lowRate, $slope] = $curve;
        $needed = (int) ceil($lowCrf + (log($ceiling) - log($lowRate)) / $slope - 1e-9);

        return max($crf, min($maxCrf, $lowCrf + self::MAX_INCREASE, $needed));
    }

    /**
     * The bitrate the anchors predict at `$crf`, or null when they can't predict one.
     *
     * @param  array<int, int>  $bitrates  crf => measured bps
     */
    public static function estimateBitrate(array $bitrates, int $crf): ?float
    {
        $curve = self::bitrateCurve($bitrates);

        if ($curve === null) {
            return null;
        }

        [$lowCrf, $lowRate, $slope] = $curve;

        return $lowRate * exp($slope * ($crf - $lowCrf));
    }

    /**
     * @param  array<int, int>  $bitrates
     * @return ?array{0: int, 1: int, 2: float} low anchor crf, its bps, d ln(bps) / d crf
     */
    private static function bitrateCurve(array $bitrates): ?array
    {
        if (count($bitrates) !== 2 || min($bitrates) <= 0) {
            return null;
        }

        ksort($bitrates);
        [$lowCrf, $highCrf] = array_keys($bitrates);
        [$lowRate, $highRate] = array_values($bitrates);

        if ($highCrf <= $lowCrf || $highRate >= $lowRate) {
            return null;
        }

        return [$lowCrf, $lowRate, (log($highRate) - log($lowRate)) / ($highCrf - $lowCrf)];
    }

    /**
     * Encode every (anchor × window) sample in ONE pool, then VMAF-score them all in a second —
     * each probe encode is short and thread-capped, and pooling both anchors together halves the
     * wall time this adds to PrepareVideoJob's fixed budget. Windows pool into a harmonic mean
     * so one bad window drags its anchor down more than a plain average would.
     *
     * The samples' sizes come along for free: they're the bitrate each anchor costs on this source,
     * pooled only over windows every anchor encoded so the two points compare the same footage.
     * Encoded under the template's VBV rather than the source-tightened one, which only ever trims
     * them further, so they read what the CRF itself spends.
     *
     * @param  list<int>  $anchorCrfs
     * @param  list<float>  $windows
     * @return array{0: array<int, float>, 1: array<int, int>} [crf => pooled vmaf score, crf => bps]
     */
    private function measureAnchors(array $anchorCrfs, string $crfKey, array $windows, string $sourcePath, Closure $tick): array
    {
        $jobs = [];
        foreach ($anchorCrfs as $crf) {
            foreach ($windows as $i => $start) {
                $jobs[] = [
                    'crf' => $crf,
                    'window' => $i,
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

            // Read before the VMAF pass overwrites the timings: a batch runs concurrently, so its
            // wall time is one encode's, not the sum — which is the number a chunk will live by.
            $this->recordSampledRate();

            // Only score what actually encoded; a lost sample costs its window, not the probe.
            $encoded = array_keys(array_filter($encodes, fn (?string $output) => $output !== null));

            // Read now: the finally below deletes the samples.
            $bitrates = $this->pooledBitrates($jobs, $encoded, $anchorCrfs);

            $outputs = $this->runPool(array_map(
                fn (int $index) => $this->vmafCommand($jobs[$index]['start'], $sourcePath, $jobs[$index]['sample']),
                $encoded,
            ), $tick);
        } finally {
            array_map(fn (array $job) => @unlink($job['sample']), $jobs);
        }

        $byWindow = [];
        foreach ($outputs as $position => $output) {
            $score = $output === null ? null : self::parseVmafScore($output);

            if ($score !== null) {
                $job = $jobs[$encoded[$position]];
                $byWindow[$job['window']][$job['crf']] = $score;
            }
        }

        // Pooled over the windows every anchor scored, for the same reason as the bitrates: an
        // anchor that lost the hardest window would score higher than the footage warrants and
        // bend the slope. Interpolating needs both ends, so with no window in common there's no curve.
        $common = array_filter($byWindow, fn (array $perAnchor) => count($perAnchor) === count($anchorCrfs));

        if (! $common) {
            throw new RuntimeException('No sample window scored for every anchor');
        }

        $scores = [];
        foreach ($anchorCrfs as $crf) {
            $windowScores = array_column($common, $crf);
            $scores[$crf] = count($windowScores) / array_sum(array_map(fn (float $s) => 1 / max($s, 1.0), $windowScores));
        }

        return [$scores, $bitrates];
    }

    /**
     * Mean bps per anchor over the windows every anchor wrote. A window one anchor lost would
     * otherwise put different footage on either end of the curve; with no window in common the
     * curve is empty, and {@see capCrfToBitrate} leaves the VMAF choice alone.
     *
     * @param  list<array{crf: int, window: int, start: float, sample: string}>  $jobs
     * @param  list<int>  $encoded  indexes into $jobs whose encode succeeded
     * @param  list<int>  $anchorCrfs
     * @return array<int, int> crf => bps
     */
    private function pooledBitrates(array $jobs, array $encoded, array $anchorCrfs): array
    {
        $bytes = [];

        foreach ($encoded as $index) {
            $size = (int) (@filesize($jobs[$index]['sample']) ?: 0);

            if ($size > 0) {
                $bytes[$jobs[$index]['window']][$jobs[$index]['crf']] = $size;
            }
        }

        $common = array_filter($bytes, fn (array $perAnchor) => count($perAnchor) === count($anchorCrfs));

        if (! $common) {
            return [];
        }

        $bitrates = [];

        foreach ($anchorCrfs as $crf) {
            $total = array_sum(array_map(fn (array $perAnchor) => $perAnchor[$crf], $common));
            $bitrates[$crf] = (int) round($total * 8 / (count($common) * SampleEncode::SECONDS));
        }

        return $bitrates;
    }

    private function encodeSampleCommand(int $crf, string $crfKey, float $start, string $sourcePath, string $samplePath): string
    {
        // Replicated stream so the anchor CRF renders through the exact same argument builder
        // (scale, GOP, *-params, the template's VBV) the real chunk encode will use. Except two
        // things. The GPU scale filter: vpp_qsv needs a hw device this command never sets up, so
        // blinding the replica's pix_fmt meta drops it to the software decode+scale fallback path.
        // And the source clamp: a VBV tightened to the source makes both anchors score alike, the
        // flat curve reads as saturation, and the probe walks the CRF up into starved scenes.
        $probe = $this->stream->replicate();
        $probe->input_params = [$crfKey => $crf] + ($probe->input_params ?? []);
        $probe->meta = array_diff_key($probe->meta ?? [], ['source_pix_fmt' => true]);
        $service = new ChunkTranscodeService($probe);

        return sprintf(
            'ffmpeg -hide_banner -y -ss %.3f -t %d -i %s -fps_mode passthrough %s -f %s %s',
            $start,
            SampleEncode::SECONDS,
            escapeshellarg($sourcePath),
            $service->buildVideoArguments(windowed: true, clampToSource: false),
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
            SampleEncode::SECONDS,
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
        $this->poolBatchSeconds = [];

        foreach (array_chunk($commands, self::MAX_CONCURRENCY) as $batch) {
            $batchStartedAt = microtime(true);

            try {
                $results = Process::pool(function (Pool $pool) use ($batch) {
                    foreach ($batch as $command) {
                        $pool->timeout(SampleEncode::TIMEOUT)->command($command);
                    }
                })->start(fn () => $tick())->wait();

                $succeeded = 0;

                foreach ($results->collect() as $result) {
                    $outputs[] = $result->successful() ? $result->output().$result->errorOutput() : null;
                    $succeeded += $result->successful() ? 1 : 0;
                }

                // Only a batch where everything ran to completion is worth timing. A timeout would
                // clock SampleEncode::TIMEOUT instead of the encode — 15x the real cost per source
                // second — and since the highest reading wins, one stuck sample would poison the
                // rate for the whole rendition. A batch that failed fast lies the other way.
                if ($succeeded === count($batch)) {
                    $this->poolBatchSeconds[] = microtime(true) - $batchStartedAt;
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

    /**
     * Mean batch wall time as a per-encode cost. The batches run {@see MAX_CONCURRENCY} encodes at
     * once, so a batch takes as long as its slowest member rather than the sum — which makes this
     * the cost of one encode with company, the same shape a chunk job runs in.
     */
    private function recordSampledRate(): void
    {
        $batches = array_filter($this->poolBatchSeconds, fn (float $seconds) => $seconds > 0.0);

        if (! $batches) {
            return;
        }

        $this->recordEncodeRate($this->stream, array_sum($batches) / count($batches), SampleEncode::SECONDS);
    }

    private function shouldProbe(?string $crfKey, float $duration): bool
    {
        $params = $this->stream->input_params ?? [];

        if (! $crfKey || empty($params['target_vmaf']) || ! isset($params[$crfKey])) {
            return false;
        }

        // Codec gate, not just a panel gate: templates saved before a codec was dropped from
        // `target_vmaf` still carry the field, and validation never strips it.
        if (! in_array($params['video_codec'] ?? null, config('ffmpeg.parameters.target_vmaf.available_for', []), true)) {
            return false;
        }

        // ABR mode picks bitrate explicitly; per-title only steers CRF. No copy-detection guard:
        // video chunks are always window-cut, so the copy fast-path never applies to them.
        if (! empty($params['constant_bitrate'])) {
            return false;
        }

        if ($duration < SampleEncode::MIN_DURATION || ! $this->stream->width || ! $this->stream->height) {
            return false;
        }

        // Redelivery: a previous attempt already resolved this stream.
        return ! isset($this->stream->meta['per_title']);
    }

    /** Quality knobs the probe can steer: CRF (CPU codecs) and QSV ICQ, both CRF-like scales. */
    private const QUALITY_TEMPLATES = ['-crf %s', '-global_quality %s'];

    /**
     * The quality field and its ceiling for this stream's codec, from config/ffmpeg.php — the
     * same source of truth the panel and command builder use, so a new codec added there is
     * picked up here without a parallel hardcoded map.
     *
     * @return array{0: ?string, 1: int} [param key, max crf]
     */
    private function crfParameter(): array
    {
        $codec = data_get($this->stream->input_params, 'video_codec');

        foreach (config('ffmpeg.parameters') as $key => $config) {
            if (in_array($config['template'] ?? null, self::QUALITY_TEMPLATES, true) && in_array($codec, $config['available_for'] ?? [], true)) {
                return [$key, (int) ($config['max'] ?? 51)];
            }
        }

        return [null, 51];
    }
}
