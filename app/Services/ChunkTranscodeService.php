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

    public function buildVideoArguments(): string
    {
        // Copy fast-path: remux when the source already matches the target codec/size at or
        // under the target bitrate.
        //
        // CAUTION: `-c:v copy` cannot honour the accurate `-ss/-to` chunk cut — it snaps back to
        // the previous keyframe, so adjacent chunks overlap and the concat runs long. Only safe
        // for renditions that aren't window-cut; gate it off for chunked video if it ever bites.
        if ($this->shouldCopyVideo()) {
            return implode(' ', [
                '-c:v copy',
                '-map '.$this->mapTarget(),
                '-an',
            ]);
        }

        $params = $this->stream->input_params ?? [];

        $args = [];

        if (isset($params['video_codec'])) {
            $args[] = '-c:v '.$this->assertSafeArgValue($params['video_codec']);
        }

        $scale = $this->buildScaleFilter((int) $this->stream->width, (int) $this->stream->height);
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
            'libsvtav1' => '-svtav1-params scd=0'.($threads > 0 ? ":lp={$threads}" : ''),
            default => '-sc_threshold 0 -x264-params open-gop=0'.($threads > 0 ? " -threads {$threads}" : ''), // libx264
        };
        $args[] = '-map '.$this->mapTarget();
        $args[] = '-an'; // video-only: audio is its own rendition/chunk set

        return implode(' ', $args);
    }

    private function perEncoderThreads(): int
    {
        $fairShare = max(1, intdiv(Cpu::cores(), Cpu::videoWorkerProcesses()));

        $codec = data_get($this->stream->input_params, 'video_codec');

        return match (strtolower($codec)) {
            'av1', 'libsvtav1' => min(2, $fairShare),

            'h264', 'hevc', 'h265' => min(4, $fairShare),

            default => min(2, $fairShare),
        };
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
