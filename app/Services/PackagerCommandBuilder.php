<?php

namespace App\Services;

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

        $suffix = $cap === null ? '' : ".{$cap}";

        if (in_array('hls', $formats, true)) {
            $args[] = '--hls_master_playlist_output';
            $args[] = "{$outputDir}/master{$suffix}.m3u8";
            $args[] = '--hls_playlist_type';
            $args[] = 'VOD';
        }

        if (in_array('dash', $formats, true)) {
            $args[] = '--generate_static_live_mpd';
            $args[] = '--mpd_output';
            $args[] = "{$outputDir}/manifest{$suffix}.mpd";
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
}
