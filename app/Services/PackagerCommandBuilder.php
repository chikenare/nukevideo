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
     * @param  list<array{path:string,type:string,ulid:string,language?:?string,forced?:bool,name?:?string}>  $inputs
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

    /**
     * @param  array{path:string,type:string,ulid:string,language?:?string,forced?:bool,name?:?string}  $input
     * @param  list<string>  $formats
     */
    private function streamDescriptor(array $input, string $outputDir, array $formats): string
    {
        $segmentDir = "{$outputDir}/{$input['ulid']}";

        $parts = [
            "in={$input['path']}",
            "stream={$input['type']}",
            "init_segment={$segmentDir}/init.mp4",
            'segment_template='.$segmentDir.'/$Number$.m4s',
        ];

        if (in_array('hls', $formats, true)) {
            $parts[] = "playlist_name={$input['ulid']}/index.m3u8";

            if ($input['type'] === 'audio') {
                $parts[] = 'hls_group_id=audio';
                $parts[] = "hls_name={$input['ulid']}";
            }
        }

        return implode(',', $parts);
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
     * @param  list<array{path:string,type:string,ulid:string,language?:?string,forced?:bool,name?:?string}>  $subs
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
            $args[] = "{$outputDir}/_subs.m3u8";
            $args[] = '--hls_playlist_type';
            $args[] = 'VOD';
        } else {
            $args[] = '--generate_static_live_mpd';
            $args[] = '--mpd_output';
            $args[] = "{$outputDir}/_subs.mpd";
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
     * @param  array{path:string,type:string,ulid:string,language?:?string,forced?:bool,name?:?string}  $input
     */
    private function textDescriptor(array $input, string $outputDir, string $format): string
    {
        $segmentDir = "{$outputDir}/{$input['ulid']}";

        $parts = ["in={$input['path']}", 'stream=text'];

        if ($format === 'dash') {
            $parts[] = "init_segment={$segmentDir}/init.mp4";
            $parts[] = 'segment_template='.$segmentDir.'/$Number$.m4s';
        } else {
            $parts[] = 'segment_template='.$segmentDir.'/$Number$.vtt';
            $parts[] = "playlist_name={$input['ulid']}/index.m3u8";
            $parts[] = 'hls_group_id=subs';
            // Commas delimit descriptor fields, so strip them from the label.
            $parts[] = 'hls_name='.str_replace(',', ' ', $input['name'] ?? $input['language'] ?? 'Subtitles');
        }

        if (! empty($input['language'])) {
            $parts[] = "lang={$input['language']}";
        }

        $parts[] = ! empty($input['forced']) ? 'forced_subtitle=1' : 'dash_roles=subtitle';

        return implode(',', $parts);
    }
}
