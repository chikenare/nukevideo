<?php

namespace App\Services;

use App\Models\Stream;
use App\Support\Cpu;

class ChunkTranscodeService
{
    use Concerns\BuildsArguments, Concerns\DetectsStreamCopy, Concerns\ResolvesScale;

    public function __construct(
        private Stream $stream,
    ) {}

    /**
     * The ffmpeg output muxer (`-f`) for this stream, resolved per codec from config/ffmpeg.php.
     * Passed explicitly because the `.part` output paths give ffmpeg no extension to infer it from.
     */
    public function outputFormat(): string
    {
        if ($this->stream->type === 'subtitle') {
            return 'webvtt';
        }

        $codec = $this->stream->type === 'video'
            ? data_get($this->stream->input_params, 'video_codec')
            : data_get($this->stream->input_params, 'audio_codec', 'aac');

        return self::formatForCodec($codec);
    }

    /**
     * The container/muxer a codec is packaged into (config/ffmpeg.php `format`). This doubles as
     * the stored file extension and the ffmpeg `-f` muxer, so the two can never disagree. nginx-vod
     * reads MP4 only, so every codec we serve (incl. Opus, via ISO-BMFF) maps to mp4; this stays
     * codec-driven for any future non-mp4 container. Falls back to mp4 for unknown/unset codecs.
     */
    public static function formatForCodec(?string $codec): string
    {
        return collect(config('ffmpeg.codecs'))->firstWhere('codec', $codec)['format'] ?? 'mp4';
    }

    /** Hardware a codec encodes on (config/ffmpeg.php `accel`): 'intel', 'nvidia', or null for CPU. */
    public static function accelForCodec(?string $codec): ?string
    {
        return collect(config('ffmpeg.codecs'))->firstWhere('codec', $codec)['accel'] ?? null;
    }

    /**
     * Whether this stream's encoder can run on the node executing right now. Chunk jobs are routed
     * to matching hardware by {@see \App\Models\Stream::encodeQueue}, but the orchestration jobs
     * that encode a sample themselves (the bitrate probe, the preflight) run wherever they land —
     * and `av1_qsv` on a node with no QSV device fails for want of hardware, not of parameters.
     */
    public function runsOnThisNode(): bool
    {
        $accel = self::accelForCodec(data_get($this->stream->input_params, 'video_codec'));

        return $accel === null || $accel === config('ffmpeg.node_accel');
    }

    /** Source codecs every supported GPU generation decodes in hardware. */
    private const HW_DECODABLE_CODECS = ['h264', 'hevc', 'av1', 'vp9'];

    /** 4:2:0 8/10-bit — what the media engines actually accept; anything else decodes in software. */
    private const HW_DECODABLE_FORMATS = ['yuv420p', 'yuvj420p', 'nv12', 'yuv420p10le', 'p010le'];

    /**
     * Pre-`-i` flags for this stream's decode. GPU renditions hardware-decode when the source
     * qualifies, so frames stay in VRAM end to end; when it doesn't, the software fallback caps
     * decoder threads — N concurrent GPU jobs with unbounded decoders oversubscribe the node
     * (the encode itself costs no CPU, so the CPU pool sizing doesn't account for them).
     */
    public function inputArguments(bool $windowed = false): string
    {
        if ($this->stream->type !== 'video') {
            return '';
        }

        $accel = self::accelForCodec(data_get($this->stream->input_params, 'video_codec'));

        if (! $accel || (! $windowed && $this->shouldCopyVideo())) {
            return '';
        }

        if (! $this->hardwareDecodes()) {
            $threads = $this->perEncoderThreads();

            return $threads > 0 ? "-threads {$threads} " : '';
        }

        return match ($accel) {
            'intel' => '-hwaccel qsv -hwaccel_output_format qsv ',
            'nvidia' => '-hwaccel cuda -hwaccel_output_format cuda ',
        };
    }

    private function hardwareDecodes(): bool
    {
        $meta = $this->stream->meta ?? [];

        return in_array($meta['source_codec'] ?? '', self::HW_DECODABLE_CODECS, true)
            && in_array($meta['source_pix_fmt'] ?? '', self::HW_DECODABLE_FORMATS, true);
    }

    /** Scale on the GPU so hardware-decoded frames never round-trip to system memory. */
    private function gpuScaleFilter(string $accel, int $width, int $height, string $codec): ?string
    {
        if ($width <= 0 || $height <= 0) {
            return null;
        }

        $format = $this->gpuEncodeFormat($codec);

        return match ($accel) {
            'intel' => "-vf vpp_qsv=w={$width}:h={$height}:format={$format}",
            'nvidia' => "-vf scale_cuda={$width}:{$height}:format={$format}",
        };
    }

    /** 10-bit 4:2:0 source formats, as ffprobe reports them. */
    private const TEN_BIT_FORMATS = ['yuv420p10le', 'p010le'];

    /**
     * AV1 always encodes 10-bit, even from 8-bit sources: same speed and weight on the media
     * engine, and the extra precision kills dark-gradient banding — the encoder has no
     * per-block AQ to fight it otherwise. H.264 is 8-bit only; HEVC follows the source.
     */
    private function gpuEncodeFormat(string $codec): string
    {
        if (str_starts_with($codec, 'h264')) {
            return 'nv12';
        }

        $tenBitSource = in_array($this->stream->meta['source_pix_fmt'] ?? '', self::TEN_BIT_FORMATS, true);

        return str_starts_with($codec, 'av1') || $tenBitSource ? 'p010le' : 'nv12';
    }

    public function buildVideoArguments(bool $windowed = false): string
    {
        // Copy fast-path: remux when the source already matches the target codec/size at or under
        // the target bitrate. Skipped for window-cut chunks: `-c:v copy` can't honour the accurate
        // `-ss/-to` cut — it snaps back to the previous keyframe, so adjacent chunks overlap and the
        // concatenated rendition runs long. Video is always chunked today, so this only ever remuxes
        // a whole-source (non-windowed) pass.
        if (! $windowed && $this->shouldCopyVideo()) {
            return implode(' ', [
                '-c:v copy',
                '-map '.$this->mapTarget(),
                '-an',
            ]);
        }

        $params = $this->resolveRateControl($this->stream->input_params ?? []);

        $accel = self::accelForCodec($params['video_codec'] ?? null);

        $args = [];

        if (isset($params['video_codec'])) {
            $args[] = '-c:v '.$this->assertSafeArgValue($params['video_codec']);
        }

        if ($accel && $this->hardwareDecodes()) {
            $scale = $this->gpuScaleFilter($accel, (int) $this->stream->width, (int) $this->stream->height, $params['video_codec']);
        } else {
            $scale = $this->buildScaleFilter((int) $this->stream->width, (int) $this->stream->height);

            // Software fallback/probe path of a GPU encoder: match the hardware path's depth.
            if ($accel && $this->gpuEncodeFormat($params['video_codec']) === 'p010le') {
                $scale = $scale ? "{$scale},format=p010le" : '-vf format=p010le';
            }
        }

        if ($scale) {
            $args[] = $scale;
        }

        $args = array_merge($args, $this->buildParamsArguments($params, 'video'));

        // Force an ABR-aligned keyframe grid: disable scene-cut (else keyframes drift off the
        // `-g` grid and misalign across renditions) and close the GOP. Flags and the thread
        // syntax are codec-specific — `-threads`/`-sc_threshold` only reach libx264, while x265
        // and svtav1 need `pools=`/`lp=` inside their *-params strings.
        $threads = $this->perEncoderThreads();
        $args[] = match ($params['video_codec'] ?? null) {
            'libx265' => '-x265-params scenecut=0:open-gop=0'.($threads > 0 ? ":pools={$threads}" : ''),
            'libsvtav1' => $this->svtAv1Params($params, $threads),
            // QSV: pin I-frames to the -g grid; -mbbrc = adaptive per-block quant (h264/hevc; AV1
            // has no such option). AV1's BRC lever is -extbrc + lookahead instead — but only in
            // quality mode; under VBR (pinned -b:v) the AV1 runtime rejects extension flags.
            'h264_qsv', 'hevc_qsv' => '-adaptive_i 0 -mbbrc 1',
            'av1_qsv' => '-adaptive_i 0'.(empty($params['constant_bitrate']) ? ' -extbrc 1 -look_ahead_depth 16' : ''),
            'h264_nvenc', 'hevc_nvenc', 'av1_nvenc' => '-no-scenecut 1 -forced-idr 1'.$this->nvencBitrateReset($params),
            default => '-sc_threshold 0 -x264-params open-gop=0'.($threads > 0 ? " -threads {$threads}" : ''), // libx264
        };
        $args[] = '-map '.$this->mapTarget();
        $args[] = '-an'; // video-only: audio is its own rendition/chunk set

        return implode(' ', $args);
    }

    // Below this a source bitrate is bogus probe data, and the clamp would emit '-maxrate 0k'.
    private const MIN_CLAMP_BPS = 100_000;

    /**
     * The rendition's share of the source's own bitrate: the ceiling a re-encode must not outweigh.
     * Null when the probe data can't support one, or when the target codec is less efficient than
     * the source's — matching an AV1 source's bitrate with x264 would starve it.
     */
    public function sourceBitrateCap(): ?int
    {
        $meta = $this->stream->meta ?? [];

        if (self::codecRank(data_get($this->stream->input_params, 'video_codec')) < self::codecRank($meta['source_codec'] ?? null)) {
            return null;
        }

        $sourceRate = (int) ($meta['source_bit_rate'] ?? 0);
        $sourcePixels = (int) ($meta['source_width'] ?? 0) * (int) ($meta['source_height'] ?? 0);
        $targetPixels = (int) $this->stream->width * (int) $this->stream->height;

        if ($sourceRate <= 0 || $sourcePixels <= 0 || $targetPixels <= 0) {
            return null;
        }

        // Bitrate scales sublinearly with resolution (~0.75 power law).
        $cap = (int) round($sourceRate * min(1.0, ($targetPixels / $sourcePixels) ** 0.75));

        return $cap >= self::MIN_CLAMP_BPS ? $cap : null;
    }

    /** Encoders whose quality mode honours no VBV, so their ceiling can't be left to the encoder. */
    private const VBV_BLIND_CODECS = ['av1_qsv'];

    /**
     * Whether this rendition's ceiling has to be measured before the encode instead of enforced
     * during it — the one case {@see capBlindQualityMode} needs a number for.
     */
    public function needsQualityBitrateProbe(): bool
    {
        $params = $this->stream->input_params ?? [];

        return in_array($params['video_codec'] ?? null, self::VBV_BLIND_CODECS, true)
            && empty($params['constant_bitrate'])
            && $this->sourceBitrateCap() !== null;
    }

    /**
     * Sample windows sit in the busier middle of a runtime, so a small overshoot isn't the whole
     * film — only a real one is worth giving up the quality mode for.
     */
    private const OVERSHOOT_TOLERANCE = 1.2;

    /**
     * The one place a rendition's rate control is decided: the mode the encoder runs in and the
     * ceiling it may not cross. Every branch answers the same question — never spend more than the
     * source did ({@see sourceBitrateCap}) — and differs only in the lever that encoder honours.
     */
    private function resolveRateControl(array $params): array
    {
        // ABR: the template pinned an average, so it carries its own ceiling.
        if (! empty($params['constant_bitrate'])) {
            return self::normalizeAbr($params);
        }

        $cap = $this->sourceBitrateCap();

        if (in_array($params['video_codec'] ?? null, self::VBV_BLIND_CODECS, true)) {
            return $this->capBlindQualityMode($params, $cap);
        }

        if ($cap !== null) {
            $params = $this->clampMaxrateToSource($params, $cap);
        }

        return self::accelForCodec($params['video_codec'] ?? null) === 'intel'
            ? $this->steerQsvRateControl($params)
            : $params;
    }

    /**
     * AV1 on QSV honours no VBV: `-maxrate` silently selects CQP at the driver's default QP and
     * `-global_quality` stops being read with it, and the runtime has no QVBR either (measured on
     * Arc B580 / iHD 1.22). Its ceiling can only be chosen before the encode, from what the quality
     * mode was measured to cost on this source ({@see QualityBitrateProbe}): an overshoot is
     * re-issued as capped VBR, anything else stays in ICQ with no VBV attached at all.
     */
    private function capBlindQualityMode(array $params, ?int $cap): array
    {
        $measured = (int) data_get($this->stream->meta, 'quality_bitrate', 0);

        if ($cap === null || $measured <= $cap * self::OVERSHOOT_TOLERANCE) {
            unset($params['maxrate'], $params['bufsize']);

            return $params;
        }

        return [
            ...self::withoutQualityKnob($params),
            'constant_bitrate' => self::kbps($cap * self::AVERAGE_TARGET_RATIO),
            'maxrate' => self::kbps($cap),
            'bufsize' => self::kbps($cap * 2),
        ];
    }

    /** Encoders that abort when a quality target is pinned next to an average bitrate. */
    private const ABR_WITHOUT_QUALITY_KNOB = ['libsvtav1', 'av1_qsv'];

    /** Encoders that abort when a VBV is attached to an average bitrate. */
    private const ABR_WITHOUT_VBV = ['libsvtav1'];

    /**
     * A pinned average is its own ceiling, but not every encoder takes the rest of the template
     * next to it: SVT-AV1 refuses a quality target ("Target Bitrate only supported when --rc is
     * 1/2 (VBR/CBR)") and a VBV ("Max Bitrate only supported with CRF mode"), and AV1 on QSV
     * refuses the quality target ("Invalid argument"). Each aborts the encode having written
     * nothing, so the conflicting knobs are dropped here — before the chunks fan out — instead of
     * failing a video at the first chunk. QSV's h264/hevc pair quality with a bitrate on purpose
     * (that combination is QVBR) and nvenc reads it as a VBR quality target, so both keep theirs.
     */
    private static function normalizeAbr(array $params): array
    {
        $codec = $params['video_codec'] ?? null;

        if (in_array($codec, self::ABR_WITHOUT_QUALITY_KNOB, true)) {
            $params = self::withoutQualityKnob($params);
        }

        if (in_array($codec, self::ABR_WITHOUT_VBV, true)) {
            unset($params['maxrate'], $params['bufsize']);
        }

        return $params;
    }

    private static function withoutQualityKnob(array $params): array
    {
        unset($params['qsv_global_quality'], $params['nvenc_cq'], $params['crf'], $params['svtav1_crf']);

        return $params;
    }

    /**
     * Safety net for already-compressed sources: tighten the template's own VBV to the cap so a
     * re-encode never outweighs its source. Only ever tightens — a template asking for less than
     * the source already respects the ceiling.
     */
    private function clampMaxrateToSource(array $params, int $cap): array
    {
        if (empty($params['maxrate'])) {
            return $params;
        }

        $maxrate = $this->parseBitrateValue($params['maxrate']);

        if ($cap >= $maxrate) {
            return $params;
        }

        if (! empty($params['bufsize'])) {
            // Keep the template's own bufsize:maxrate ratio — a strict 1x VBV stays strict.
            $params['bufsize'] = self::kbps($cap * $this->parseBitrateValue($params['bufsize']) / max(1, $maxrate));
        }

        $params['maxrate'] = self::kbps($cap);

        return $params;
    }

    /** Rough compression-efficiency ordering of codec generations. */
    private static function codecRank(?string $codec): int
    {
        return match ($codec) {
            'libsvtav1', 'av1', 'av1_qsv', 'av1_nvenc' => 3,
            'libx265', 'hevc', 'vp9', 'hevc_qsv', 'hevc_nvenc' => 2,
            default => 1, // h264 family and anything older/unknown
        };
    }

    /** A pinned average as a fraction of the peak cap, shared by QVBR steering and the VBR fallback. */
    private const AVERAGE_TARGET_RATIO = 0.75;

    /**
     * Steer capped QSV quality modes into QVBR (verified on Arc B580/iHD): global_quality+maxrate
     * alone selects CQP and silently drops both, and QVBR only engages with a -b:v below the cap.
     * AV1 never reaches this — it has no QVBR ({@see capBlindQualityMode}).
     */
    private function steerQsvRateControl(array $params): array
    {
        if (empty($params['qsv_global_quality']) || empty($params['maxrate'])) {
            return $params;
        }

        $params['constant_bitrate'] = self::kbps($this->parseBitrateValue($params['maxrate']) * self::AVERAGE_TARGET_RATIO);

        return $params;
    }

    private static function kbps(float $bps): string
    {
        return max(1, (int) round($bps / 1000)).'k';
    }

    /** NVENC's CQ mode only bites with `-b:v 0` — its default 2M bitrate target caps it otherwise. */
    private function nvencBitrateReset(array $params): string
    {
        return ! empty($params['nvenc_cq']) && empty($params['constant_bitrate']) ? ' -b:v 0' : '';
    }

    /**
     * Single -svtav1-params flag: ffmpeg keeps only the last occurrence, so the template's
     * `svtav1_param` fields (config/ffmpeg.php) are joined with the forced ABR/thread pairs.
     */
    private function svtAv1Params(array $params, int $threads): string
    {
        $pairs = [];

        foreach (config('ffmpeg.parameters') as $key => $config) {
            $svtKey = $config['svtav1_param'] ?? null;
            $value = $params[$key] ?? null;

            if (! $svtKey || $value === null || $value === '') {
                continue;
            }

            if (($config['input_type'] ?? null) === 'boolean') {
                if (! $value) {
                    continue;
                }
                $value = 1;
            }

            $pairs[$svtKey] = $this->assertSafeArgValue($value);
        }

        $forced = ['scd' => '0'] + ($threads > 0 ? ['lp' => (string) $threads] : []);

        return '-svtav1-params '.collect(array_merge($pairs, $forced))
            ->map(fn ($value, $key) => "{$key}={$value}")
            ->implode(':');
    }

    /**
     * The node's fair share of threads, so processes × threads fills the CPU and no more. Derived
     * from the same sizing as the worker pool ({@see Cpu}) — a private cap here would silently
     * contradict it and leave cores idle while every chunk crawls toward the timeout.
     */
    private function perEncoderThreads(): int
    {
        return Cpu::videoEncoderThreads();
    }

    public function buildAudioArguments(): string
    {
        $params = $this->stream->input_params ?? [];

        $args = [];

        // Default to a light AAC re-encode when the template doesn't pin a codec.
        if (isset($params['audio_codec'])) {
            $args[] = '-c:a '.$this->assertSafeArgValue($params['audio_codec']);
            $args = array_merge($args, $this->buildParamsArguments($params, 'audio'));
        } else {
            $args[] = '-c:a aac -b:a 128k';
        }

        $args[] = '-map '.$this->mapTarget();
        $args[] = '-vn'; // audio-only

        return implode(' ', $args);
    }

    /**
     * The source track this rendition encodes. Uses the stream's absolute index (not
     * 0:v:0 / 0:a:0) so multi-track sources (e.g. several audio languages) each map their
     * own track instead of collapsing onto the first.
     */
    public function mapTarget(): string
    {
        $index = $this->stream->meta['index'] ?? null;

        if ($index !== null) {
            return "0:{$index}";
        }

        return match ($this->stream->type) {
            'video' => '0:v:0',
            'subtitle' => '0:s:0',
            default => '0:a:0',
        };
    }
}
