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
     * @param  list<array{path:string,type:string,ulid:string}>  $inputs  local rendition files
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
     * @param  array{path:string,type:string,ulid:string}  $input
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
     * Build a packager run that turns each subtitle VTT into a single-segment raw WebVTT (`text/vtt`)
     * track under `subtitles/{ulid}/`, emitting a throwaway DASH manifest (`_subs.mpd`) whose text
     * AdaptationSet is grafted into the real manifests ({@see ManifestEditor::importDashSubtitles}).
     * A `$segmentDuration` longer than the video forces a single segment. DASH only — HLS subtitles
     * use their own plain-VTT media playlists built separately.
     *
     * @param  list<array{ulid:string,path:string,language:?string,forced:bool}>  $subs
     * @return list<string>
     */
    public function buildText(array $subs, string $outputDir, int $segmentDuration): array
    {
        $args = [$this->bin];

        foreach ($subs as $sub) {
            $args[] = $this->textDescriptor($sub, $outputDir);
        }

        $args[] = '--segment_duration';
        $args[] = (string) $segmentDuration;
        $args[] = '--generate_static_live_mpd';
        $args[] = '--mpd_output';
        $args[] = "{$outputDir}/_subs.mpd";

        return $args;
    }

    /**
     * @param  array{ulid:string,path:string,language:?string,forced:bool}  $sub
     */
    private function textDescriptor(array $sub, string $outputDir): string
    {
        $segmentDir = "{$outputDir}/subtitles/{$sub['ulid']}";

        $parts = [
            "in={$sub['path']}",
            'stream=text',
            "init_segment={$segmentDir}/init.mp4",
            'segment_template='.$segmentDir.'/$Number$.vtt',
        ];

        if ($sub['language']) {
            $parts[] = "lang={$sub['language']}";
        }

        if ($sub['forced']) {
            $parts[] = 'forced_subtitle=1';
        } else {
            $parts[] = 'dash_roles=subtitle';
        }

        return implode(',', $parts);
    }
}
