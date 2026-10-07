<?php

namespace App\Services;

use App\Jobs\ProcessChunkJob;
use App\Models\Stream;
use App\Support\Cpu;
use InvalidArgumentException;

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

    /**
     * Tone mapping runs in software (zscale has no GPU twin in this build), so a rendition that
     * tone-maps decodes in software too: hardware frames would only be downloaded again to reach it.
     * `force_software_decode` is the probes' switch ({@see PerTitleCrfService}): their command sets
     * up no hardware device, but must otherwise build exactly what the chunk encode will.
     */
    private function hardwareDecodes(): bool
    {
        $meta = $this->stream->meta ?? [];

        return empty($meta['force_software_decode'])
            && ! $this->tonesMap()
            && in_array($meta['source_codec'] ?? '', self::HW_DECODABLE_CODECS, true)
            && in_array($meta['source_pix_fmt'] ?? '', self::HW_DECODABLE_FORMATS, true);
    }

    /**
     * Transfer functions ffprobe reports for an HDR picture: PQ (HDR10, HDR10+, and the HDR10 base
     * layer of Dolby Vision 8.1) and HLG. Anything else is graded for SDR and encodes as it is.
     */
    public const HDR_TRANSFERS = ['smpte2084', 'arib-std-b67'];

    /** Whether a source stream (ffprobe's `color_transfer`) carries an HDR picture. */
    public static function isHdrTransfer(?string $transfer): bool
    {
        return in_array($transfer, self::HDR_TRANSFERS, true);
    }

    /**
     * Whether a rendition with these resolved parameters keeps an HDR source's picture as it is.
     * That takes 10-bit HEVC or AV1: H.264 as browsers decode it is 8-bit, and eight bits spread
     * over PQ's 10,000-nit range band visibly. Mirrors what the encode will actually produce — a
     * CPU encoder follows the source's depth unless the template pins an 8-bit `pixel_format` or
     * x265 profile, GPU HEVC follows the source ({@see gpuEncodeFormat}) and GPU AV1 is always
     * 10-bit. An 8-bit "HDR" source has already lost what PQ needs, so it tone-maps.
     */
    public static function carriesHdr(array $params, ?string $sourcePixFmt): bool
    {
        if (self::bitDepth($sourcePixFmt) < 10) {
            return false;
        }

        $codec = $params['video_codec'] ?? null;
        $family = collect(config('ffmpeg.codecs'))->firstWhere('codec', $codec)['family'] ?? null;

        if (! in_array($family, ['hevc', 'av1'], true)) {
            return false;
        }

        if (self::accelForCodec($codec) !== null) {
            return true;
        }

        $format = ($params['pixel_format'] ?? null) ?: null;

        if ($format !== null && self::bitDepth($format) < 10) {
            return false;
        }

        return ! ($codec === 'libx265' && in_array($params['x265_profile'] ?? null, ['main', 'main444-8'], true));
    }

    /**
     * Bits per component of an ffprobe pixel format: the trailing `10le`/`12be` of the planar
     * names, the `010` of p010. Anything without one (yuv420p, nv12, gbrp, unknown) reads as 8.
     */
    private static function bitDepth(?string $pixFmt): int
    {
        return preg_match('/(\d{2})(le|be)$/', (string) $pixFmt, $match) ? (int) $match[1] : 8;
    }

    /**
     * Whether this rendition maps an HDR source down to SDR, decided once per output when the
     * streams are created ({@see CreateVideoStreamsService}): an output keeps HDR only when every
     * one of its renditions can carry it, so its ladder never switches dynamic range mid-playback.
     */
    public function tonesMap(): bool
    {
        return ! empty($this->stream->meta['tone_map']);
    }

    /**
     * PQ/HLG → BT.709 SDR. Linearised at 100 nits (SDR reference white), gamut-mapped in float,
     * Hable's curve rolls the highlights off instead of clipping them, then back to limited-range
     * BT.709. The HDR10 static metadata is dropped on the way out: it rides the frames as side data,
     * and x265 would otherwise write mastering-display and MaxCLL SEI into a stream that is no
     * longer HDR. Ends at `$format` explicitly — left to negotiation, the float frames would
     * reach libx264 as 4:4:4.
     */
    private const TONE_MAP_FILTER = 'zscale=t=linear:npl=100,format=gbrpf32le,zscale=p=bt709,'
        .'tonemap=tonemap=hable:desat=0,zscale=t=bt709:m=bt709:r=tv,'
        .'sidedata=mode=delete:type=MASTERING_DISPLAY_METADATA,sidedata=mode=delete:type=CONTENT_LIGHT_LEVEL';

    /**
     * The tone-mapping filter chain for this rendition, ending in the pixel format its encoder
     * gets, or null when it does not tone-map. Public for the per-title VMAF reference, which has
     * to look like what the viewer is shown — scored against the PQ source, every sample is "bad".
     */
    public function toneMapFilter(): ?string
    {
        if (! $this->tonesMap()) {
            return null;
        }

        $params = $this->stream->input_params ?? [];
        $codec = $params['video_codec'] ?? '';
        $format = self::accelForCodec($codec) ? $this->gpuEncodeFormat($codec) : $this->cpuPixelFormat($params);

        return self::TONE_MAP_FILTER.',format='.$this->assertSafeArgValue($format ?? 'yuv420p');
    }

    /**
     * The pixel format a CPU encoder is handed: the template's, or 4:2:0 at the source's depth —
     * 8-bit for H.264, which browsers decode at nothing else. Left to ffmpeg, a ProRes or DNxHR
     * master (4:2:2 10-bit) went straight through to a High 4:2:2 or Main 4:2:2 10 stream no browser
     * plays. Null when the source format is unknown: there is nothing to decide against.
     *
     * An x265 profile the template pins wins over the source: `main` is 8-bit 4:2:0, so a 10-bit
     * master gets 8-bit frames — handed 10-bit ones, x265 refuses the profile outright, and
     * {@see carriesHdr} already counts `main` as 8-bit. The 4:4:4 profiles get nothing at all, so
     * ffmpeg negotiates the source's own chroma as it always has.
     */
    private function cpuPixelFormat(array $params): ?string
    {
        if (! empty($params['pixel_format'])) {
            return (string) $params['pixel_format'];
        }

        if (($params['video_codec'] ?? null) === 'libx265') {
            $profile = $params['x265_profile'] ?? null;

            if ($profile === 'main') {
                return 'yuv420p';
            }

            if (in_array($profile, ['main444-8', 'main444-10'], true)) {
                return null;
            }
        }

        $sourceFormat = $this->stream->meta['source_pix_fmt'] ?? null;

        if ($sourceFormat === null) {
            return null;
        }

        if (($params['video_codec'] ?? null) === 'libx264') {
            return 'yuv420p';
        }

        return self::bitDepth($sourceFormat) >= 10 ? 'yuv420p10le' : 'yuv420p';
    }

    /**
     * The colour description written into the stream, so it never rests on what survives the
     * filter chain: BT.709 for a tone-mapped rendition, the source's own for an HDR one kept as it
     * is. Explicit for the GPU encoders above all — what the software path showed carrying through
     * on its own, nothing guarantees for frames that never leave VRAM. SDR sources write nothing,
     * as before.
     */
    private function colorArguments(): array
    {
        if ($this->tonesMap()) {
            return ['-color_primaries bt709', '-color_trc bt709', '-colorspace bt709'];
        }

        $meta = $this->stream->meta ?? [];

        if (! self::isHdrTransfer($meta['source_color_transfer'] ?? null)) {
            return [];
        }

        return collect([
            '-color_primaries' => $meta['source_color_primaries'] ?? null,
            '-color_trc' => $meta['source_color_transfer'],
            '-colorspace' => $meta['source_color_space'] ?? null,
        ])
            ->filter()
            ->map(fn ($value, $flag) => $flag.' '.$this->assertSafeArgValue($value))
            ->values()
            ->all();
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

    /**
     * AV1 always encodes 10-bit, even from 8-bit sources: same speed and weight on the media engine,
     * and the extra precision kills dark-gradient banding. H.264 is 8-bit only; HEVC follows the
     * source's depth — any 10-bit one, 4:2:2 masters included, since the encode is 4:2:0 either way.
     */
    private function gpuEncodeFormat(string $codec): string
    {
        if (str_starts_with($codec, 'h264')) {
            return 'nv12';
        }

        $tenBitSource = self::bitDepth($this->stream->meta['source_pix_fmt'] ?? null) >= 10;

        return str_starts_with($codec, 'av1') || $tenBitSource ? 'p010le' : 'nv12';
    }

    /**
     * `$gapFill` (['rate' => '24000/1001', 'timescale' => 24000]) resamples to that frame rate,
     * repeating a frame across any stretch the source holds none, so a chunk that ends inside such
     * a hole comes out its full length ({@see ProcessChunkJob}). Both values come from the chunk's
     * own first, short encode: its neighbours are concatenated with `-c copy`, which needs one
     * timescale — the source's rounded `source_fps` gave 23.976 a 1/11988 timescale against their
     * 1/24000, and that window played at double speed. Software filter path only.
     */
    public function buildVideoArguments(bool $windowed = false, ?array $gapFill = null): string
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

            // Scaled first: tone mapping runs in float, so it costs per OUTPUT pixel — 4x less on
            // a 1080p rung of a 4K master than ahead of the scale.
            if ($toneMap = $this->toneMapFilter()) {
                $scale = $scale ? "{$scale},{$toneMap}" : "-vf {$toneMap}";
            } elseif ($accel && $this->gpuEncodeFormat($params['video_codec']) === 'p010le') {
                // Software fallback/probe path of a GPU encoder: match the hardware path's depth.
                $scale = $scale ? "{$scale},format=p010le" : '-vf format=p010le';
            }

            if ($gapFill !== null) {
                // A rational such as 24000/1001; assertSafeArgValue()'s charset has no '/'.
                if (! preg_match('#^\d+/\d+$#', (string) $gapFill['rate'])) {
                    throw new InvalidArgumentException("Unsafe frame rate: {$gapFill['rate']}");
                }

                $filter = 'fps='.$gapFill['rate'];
                $scale = $scale ? "{$scale},{$filter}" : "-vf {$filter}";
            }
        }

        if ($scale) {
            $args[] = $scale;
        }

        $args = array_merge($args, $this->buildParamsArguments($params, 'video'));

        // The template's own `pixel_format` is already among the params above.
        if (! $accel && empty($params['pixel_format']) && ($format = $this->cpuPixelFormat($params))) {
            $args[] = '-pix_fmt '.$this->assertSafeArgValue($format);
        }

        $args = array_merge($args, $this->colorArguments());

        if ($gapFill !== null && ! ($accel && $this->hardwareDecodes())) {
            $args[] = '-video_track_timescale '.(int) $gapFill['timescale'];
        }

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
            'libx265' => $this->x265Params($params, $threads),
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
        $forced = ['scd' => '0'] + ($threads > 0 ? ['lp' => (string) $threads] : []);

        return '-svtav1-params '.$this->joinEncoderParams($params, 'svtav1_param', $forced);
    }

    /** Single -x265-params flag, for the same reason: the `x265_param` fields join the forced pairs. */
    private function x265Params(array $params, int $threads): string
    {
        $forced = ['scenecut' => '0', 'open-gop' => '0'] + ($threads > 0 ? ['pools' => (string) $threads] : []);

        return '-x265-params '.$this->joinEncoderParams($params, 'x265_param', $forced);
    }

    /**
     * `key=value:key=value` from the template fields that declare `$marker` (their key inside the
     * encoder's own *-params string), followed by `$forced`, which wins over any template value
     * for the same key — the keyframe grid is not the template's to undo.
     */
    private function joinEncoderParams(array $params, string $marker, array $forced): string
    {
        $pairs = [];

        foreach (config('ffmpeg.parameters') as $key => $config) {
            $encoderKey = $config[$marker] ?? null;
            $value = $params[$key] ?? null;

            if (! $encoderKey || $value === null || $value === '') {
                continue;
            }

            if (($config['input_type'] ?? null) === 'boolean') {
                if (! $value) {
                    continue;
                }
                $value = 1;
            }

            $pairs[$encoderKey] = $this->assertSafeArgValue($value);
        }

        return collect(array_merge($pairs, $forced))
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
