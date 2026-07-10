<?php

namespace App\Services;

use App\Models\Output;

/**
 * Builds the shaka-packager argv for one output: every rendition becomes a CMAF stream sharing
 * one segment tree, and a manifest is emitted per requested format over those same segments.
 * Returned as an argv list (never a shell string) so `$Number$` and paths need no quoting.
 */
class PackagerCommandBuilder
{
    /** Throwaway subtitle-only manifest names ({@see buildText}); never a real output's filename
     *  ({@see \App\Models\Output::manifestFile} always names a real one after the output's own ulid),
     *  so {@see \App\Jobs\PackageVideoJob::packageSubtitles} can glob for "every manifest but these". */
    public const SUBS_DASH_MANIFEST = '_subs.mpd';

    public const SUBS_HLS_MANIFEST = '_subs.m3u8';

    public function __construct(
        private string $bin,
        private int $segmentDuration,
    ) {}

    /**
     * Build one packager run. `$cap` names the manifest set: null is the full master, a height
     * (e.g. 720) yields a capped manifest listing only the renditions passed in. Every run writes
     * the same per-stream segment tree, so all manifests of this output share the segments. The
     * manifest filename is keyed by `$output`'s own ulid ({@see \App\Models\Output::manifestFile})
     * so it never collides with another output of the same video.
     *
     * @param  list<array{path:string,type:string,ulid:string,language?:?string,forced?:bool,hearing_impaired?:bool,visual_impaired?:bool,name?:?string}>  $inputs
     * @param  list<string>  $formats  any of 'hls', 'dash'
     * @return list<string>
     */
    public function build(array $inputs, string $outputDir, array $formats, Output $output, ?int $cap = null): array
    {
        $args = [$this->bin];

        foreach ($inputs as $input) {
            $args[] = $this->streamDescriptor($input, $outputDir, $formats);
        }

        $args[] = '--segment_duration';
        $args[] = (string) $this->segmentDuration;

        if (in_array('hls', $formats, true)) {
            $args[] = '--hls_master_playlist_output';
            $args[] = "{$outputDir}/".$output->manifestFile('hls', $cap);
            $args[] = '--hls_playlist_type';
            $args[] = 'VOD';
        }

        if (in_array('dash', $formats, true)) {
            $args[] = '--generate_static_live_mpd';
            $args[] = '--mpd_output';
            $args[] = "{$outputDir}/".$output->manifestFile('dash', $cap);
        }

        return $args;
    }

    /** Where one stream's CMAF segments are written, relative to the run's output dir — matches
     *  {@see \App\Models\Stream::segmentsPath} (which builds the same shape for deletion). */
    private function segmentDir(string $outputDir, string $ulid): string
    {
        return "{$outputDir}/{$ulid}";
    }

    /**
     * @param  array{path:string,type:string,ulid:string,language?:?string,forced?:bool,hearing_impaired?:bool,visual_impaired?:bool,name?:?string}  $input
     * @param  list<string>  $formats
     */
    private function streamDescriptor(array $input, string $outputDir, array $formats): string
    {
        $segmentDir = $this->segmentDir($outputDir, $input['ulid']);

        $parts = [
            "in={$input['path']}",
            "stream={$input['type']}",
            "init_segment={$segmentDir}/init.mp4",
            'segment_template='.$segmentDir.'/$Number$.m4s',
        ];

        if ($input['type'] === 'audio') {
            $parts[] = "lang={$input['language']}";

            $label = $input['name'];
            $parts[] = "dash_label={$label}";

            if (in_array('hls', $formats, true)) {
                $parts[] = 'hls_group_id=audio';
                $parts[] = "hls_name={$label}";
            }

            $parts = [...$parts, ...$this->audioAccessibility($input, $formats)];
        }

        if (in_array('hls', $formats, true)) {
            $parts[] = "playlist_name={$input['ulid']}/index.m3u8";
        }

        return implode(',', $parts);
    }

    /**
     * Accessibility descriptors for an audio track. An audio-description track wins over a
     * hearing-impaired mix when a source flags both. Values are semicolon-separated because the
     * stream descriptor itself is comma-separated; Apple defines no CHARACTERISTIC for HI audio.
     *
     * @param  array{hearing_impaired?:bool,visual_impaired?:bool}  $input
     * @param  list<string>  $formats
     * @return list<string>
     */
    private function audioAccessibility(array $input, array $formats): array
    {
        if (! empty($input['visual_impaired'])) {
            return [
                'dash_roles=description',
                'dash_accessibilities=urn:tva:metadata:cs:AudioPurposeCS:2007=1',
                ...(in_array('hls', $formats, true) ? ['hls_characteristics=public.accessibility.describes-video'] : []),
            ];
        }

        if (! empty($input['hearing_impaired'])) {
            return [
                'dash_roles=enhanced-audio-intelligibility',
                'dash_accessibilities=urn:tva:metadata:cs:AudioPurposeCS:2007=2',
            ];
        }

        return [];
    }

    /**
     * Build a SEPARATE packager run for subtitles in ONE format, with `$segmentDuration` longer than
     * the video so each track is a SINGLE segment under the stream's `{ulid}/` (a few KB — segmenting
     * them like video is pointless). DASH and HLS need DIFFERENT forms, so this runs once per format:
     * - `dash`: a single fMP4 WebVTT (`wvtt`) segment + an `init.mp4` — dashjs needs an init segment
     *   or it falls back to fetching the `<BaseURL>` directory (404). Emits throwaway `_subs.mpd`.
     * - `hls`: a single raw `text/vtt` segment — hls.js can't parse fMP4 WebVTT. Emits `_subs.m3u8`.
     * The throwaway manifest's text entries are grafted into the real manifests ({@see ManifestEditor}).
     * Separate run because `--segment_duration` is global; the main run keeps small segments for video.
     *
     * @param  list<array{path:string,type:string,ulid:string,language?:?string,forced?:bool,hearing_impaired?:bool,visual_impaired?:bool,name?:?string}>  $subs
     * @return list<string>
     */
    public function buildText(array $subs, string $outputDir, int $segmentDuration, string $format): array
    {
        $args = [$this->bin];

        foreach ($subs as $sub) {
            $args[] = $this->textDescriptor($sub, $outputDir, $format);
        }

        $args[] = '--segment_duration';
        $args[] = (string) $segmentDuration;

        if ($format === 'hls') {
            $args[] = '--hls_master_playlist_output';
            $args[] = "{$outputDir}/".self::SUBS_HLS_MANIFEST;
            $args[] = '--hls_playlist_type';
            $args[] = 'VOD';
        } else {
            $args[] = '--generate_static_live_mpd';
            $args[] = '--mpd_output';
            $args[] = "{$outputDir}/".self::SUBS_DASH_MANIFEST;
        }

        return $args;
    }

    /**
     * Subtitle stream descriptor for ONE format under the stream's own `{ulid}/` (same layout as the
     * video/audio renditions). DASH = fMP4 WebVTT (`wvtt`) with an `init_segment` (so dashjs loads a
     * real init instead of 404ing on the `<BaseURL>` dir); HLS = raw `text/vtt` segments + its media
     * playlist (hls.js can't parse fMP4 WebVTT). A long segment duration ({@see buildText}) makes
     * either a single segment.
     *
     * @param  array{path:string,type:string,ulid:string,language?:?string,forced?:bool,hearing_impaired?:bool,visual_impaired?:bool,name?:?string}  $input
     */
    private function textDescriptor(array $input, string $outputDir, string $format): string
    {
        $segmentDir = $this->segmentDir($outputDir, $input['ulid']);

        $parts = ["in={$input['path']}", 'stream=text'];

        if ($format === 'dash') {
            $parts[] = "init_segment={$segmentDir}/init.mp4";
            $parts[] = 'segment_template='.$segmentDir.'/$Number$.m4s';
            $parts[] = "dash_label={$input['name']}";
        } else {
            $parts[] = 'segment_template='.$segmentDir.'/$Number$.vtt';
            $parts[] = "playlist_name={$input['ulid']}/index.m3u8";
            $parts[] = 'hls_group_id=subs';
            $parts[] = "hls_name={$input['name']}";
        }

        $parts[] = "lang={$input['language']}";

        // A forced track already implies its own role; SDH takes `caption` instead of `subtitle`.
        if (! empty($input['forced'])) {
            $parts[] = 'forced_subtitle=1';
        } elseif (! empty($input['hearing_impaired'])) {
            $parts[] = 'dash_roles=caption';

            if ($format === 'hls') {
                $parts[] = 'hls_characteristics=public.accessibility.transcribes-spoken-dialog;public.accessibility.describes-music-and-sound';
            }
        } else {
            $parts[] = 'dash_roles=subtitle';
        }

        return implode(',', $parts);
    }
}
