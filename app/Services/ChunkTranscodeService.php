<?php

namespace App\Services;

use App\Models\Stream;
use App\Support\Cpu;

class ChunkTranscodeService
{
    use Concerns\BuildsArguments, Concerns\DetectsStreamCopy, Concerns\ResolvesRateControl, Concerns\ResolvesScale;

    public function __construct(
        private Stream $stream,
    ) {}

    /**
     * The ffmpeg output muxer (`-f`) for this stream. Passed explicitly because the `.part` output
     * paths give ffmpeg no extension to infer it from.
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
     * The container a codec is packaged into (config/ffmpeg.php `format`), doubling as the stored
     * file extension and the ffmpeg `-f` muxer so the two can never disagree. Everything we serve
     * maps to mp4 (incl. Opus, via ISO-BMFF): shaka-packager repackages ISO-BMFF into CMAF, and
     * the concat step needs every chunk in the same container anyway.
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
     * Wall time this rendition's encoder costs per pixel, against the reference the chunk windows
     * are sized on (config/ffmpeg.php `encode_cost`, libx264 `medium` = 1.0). The speed preset is
     * folded in here because it moves the cost by an order of magnitude on its own — the same
     * encoder at two presets is not the same workload, and the window planner only gets one number.
     *
     * An unknown codec or a preset that isn't on the ladder falls back to the reference rather than
     * to a guess: 1.0 reproduces the sizing this fleet ran before costs existed, which is the one
     * behaviour we know does not regress anything.
     */
    public static function encodeCost(?string $codec, ?array $params = null): float
    {
        $entry = collect(config('ffmpeg.codecs'))->firstWhere('codec', $codec) ?? [];

        $cost = (float) ($entry['encode_cost'] ?? 1.0);

        $parameter = $entry['preset_parameter'] ?? null;
        $preset = $parameter ? data_get($params, $parameter) : null;
        $ladder = $entry['preset_cost'] ?? [];

        // Presets arrive from the template's JSON, so a name is a string and a rung is an int or
        // its numeric string; anything else is a malformed template and takes the fallback.
        if ((is_string($preset) || is_int($preset)) && isset($ladder[$preset])) {
            $cost *= (float) $ladder[$preset];
        }

        return $cost > 0 ? $cost : 1.0;
    }

    /**
     * Whether this stream's encoder can run on the node executing right now. Chunk jobs are routed
     * to matching hardware by {@see Stream::encodeQueue}, but the orchestration jobs that
     * encode a sample themselves ({@see SampleEncode}) run wherever they land — and `av1_qsv` on a
     * node with no QSV device fails for want of hardware, not of parameters.
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
     * AV1 always encodes 10-bit, even from 8-bit sources: same speed and weight on the media engine,
     * and the extra precision kills dark-gradient banding. H.264 is 8-bit only; HEVC follows the source.
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
        // Copy fast-path: remux when the source already matches the target codec/size at or under the
        // target bitrate. Never for window-cut chunks — `-c:v copy` snaps back to the previous
        // keyframe, so adjacent chunks overlap and the concatenated rendition runs long.
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

        $args[] = $this->keyframeGridArguments($params);
        $args[] = '-map '.$this->mapTarget();
        $args[] = '-an'; // video-only: audio is its own rendition/chunk set

        return implode(' ', $args);
    }

    /**
     * Force an ABR-aligned keyframe grid: disable scene-cut (else keyframes drift off the `-g` grid
     * and misalign across renditions) and close the GOP. The flags and the thread syntax are
     * codec-specific — `-threads`/`-sc_threshold` only reach libx264, while x265 and svtav1 need
     * `pools=`/`lp=` inside their *-params strings.
     */
    private function keyframeGridArguments(array $params): string
    {
        $threads = $this->perEncoderThreads();

        return match ($params['video_codec'] ?? null) {
            'libx265' => '-x265-params scenecut=0:open-gop=0'.($threads > 0 ? ":pools={$threads}" : ''),
            'libsvtav1' => $this->svtAv1Params($params, $threads),
            // QSV: pin I-frames to the -g grid; -mbbrc = adaptive per-block quant (h264/hevc only).
            // AV1's BRC lever is -extbrc + lookahead instead, and only in quality mode — under VBR
            // (pinned -b:v) the AV1 runtime rejects extension flags.
            'h264_qsv', 'hevc_qsv' => '-adaptive_i 0 -mbbrc 1',
            'av1_qsv' => '-adaptive_i 0'.(empty($params['constant_bitrate']) ? ' -extbrc 1 -look_ahead_depth 16' : ''),
            'h264_nvenc', 'hevc_nvenc', 'av1_nvenc' => '-no-scenecut 1 -forced-idr 1'.$this->nvencBitrateReset($params),
            default => '-sc_threshold 0 -x264-params open-gop=0'.($threads > 0 ? " -threads {$threads}" : ''), // libx264
        };
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
     * The source track this rendition encodes. Uses the stream's absolute index (not 0:v:0 / 0:a:0)
     * so multi-track sources each map their own track instead of collapsing onto the first.
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
