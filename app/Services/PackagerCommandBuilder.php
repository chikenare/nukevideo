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
     * Build one packager run. `$cap` names the manifest set: null is the full `master`/`manifest`,
     * a height (e.g. 720) yields `master.720`/`manifest.720` listing only the renditions passed in.
     * Every run writes the same per-stream segment tree, so all manifests share the segments.
     *
     * @param  list<array{path:string,type:string,ulid:string,language?:?string,forced?:bool,name?:?string}>  $inputs
     * @param  list<string>  $formats  any of 'hls', 'dash'
     * @return list<string>
     */
    public function build(array $inputs, string $outputDir, array $formats, ?int $cap = null): array
    {
        $args = [$this->bin];

        foreach ($inputs as $input) {
            $args[] = $this->streamDescriptor($input, $outputDir, $formats);
        }

        $args[] = '--segment_duration';
        $args[] = (string) $this->segmentDuration;

        if (in_array('hls', $formats, true)) {
            $args[] = '--hls_master_playlist_output';
            $args[] = "{$outputDir}/".Output::manifestFile('hls', $cap);
            $args[] = '--hls_playlist_type';
            $args[] = 'VOD';
        }

        if (in_array('dash', $formats, true)) {
            $args[] = '--generate_static_live_mpd';
            $args[] = '--mpd_output';
            $args[] = "{$outputDir}/".Output::manifestFile('dash', $cap);
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
     * Build a SEPARATE packager run for subtitles, with `$segmentDuration` longer than the video so
     * each track becomes a SINGLE raw-WebVTT (`text/vtt`) segment under `subtitles/{ulid}/` — they're
     * a few KB, so segmenting them like video is pointless. Emits throwaway `_subs.mpd` / `_subs.m3u8`
     * whose text entries are grafted into the real manifests ({@see ManifestEditor}). It's a separate
     * run because `--segment_duration` is global; the main run keeps small segments for video.
     *
     * @param  list<array{path:string,type:string,ulid:string,language?:?string,forced?:bool,name?:?string}>  $subs
     * @param  list<string>  $formats
     * @return list<string>
     */
    public function buildText(array $subs, string $outputDir, int $segmentDuration, array $formats): array
    {
        $args = [$this->bin];

        foreach ($subs as $sub) {
            $args[] = $this->textDescriptor($sub, $outputDir, $formats);
        }

        $args[] = '--segment_duration';
        $args[] = (string) $segmentDuration;

        if (in_array('hls', $formats, true)) {
            $args[] = '--hls_master_playlist_output';
            $args[] = "{$outputDir}/_subs.m3u8";
            $args[] = '--hls_playlist_type';
            $args[] = 'VOD';
        }

        if (in_array('dash', $formats, true)) {
            $args[] = '--generate_static_live_mpd';
            $args[] = '--mpd_output';
            $args[] = "{$outputDir}/_subs.mpd";
        }

        return $args;
    }

    /**
     * Subtitle stream descriptor: raw WebVTT segments (`text/vtt`, not fMP4 `wvtt`) under
     * `subtitles/{ulid}/`. `SegmentTemplate` is kept (a non-fragmented `<BaseURL>` VTT would make
     * dashjs deref null and crash); with a long segment duration it yields a single segment.
     *
     * @param  array{path:string,type:string,ulid:string,language?:?string,forced?:bool,name?:?string}  $input
     * @param  list<string>  $formats
     */
    private function textDescriptor(array $input, string $outputDir, array $formats): string
    {
        $segmentDir = "{$outputDir}/subtitles/{$input['ulid']}";

        $parts = [
            "in={$input['path']}",
            'stream=text',
            'segment_template='.$segmentDir.'/$Number$.vtt',
        ];

        if (in_array('hls', $formats, true)) {
            $parts[] = "playlist_name=subtitles/{$input['ulid']}/index.m3u8";
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
