<?php

namespace App\Services;

use App\Models\Output;
use App\Models\Stream;
use App\Models\Video;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Storage;

/**
 * Surgically edits a completed video's CMAF manifests on primary S3 so they stay consistent when a
 * stream is removed — without re-running the packager. Every stream is packaged into a directory
 * named by its ULID ({@see PackagerCommandBuilder}), so each variant is located by that ULID in the
 * manifests (HLS `URI`, DASH `SegmentTemplate@media`). Manifests are KB, so this runs inline.
 * Subtitles are packaged single-segment in a separate run and grafted into the manifests at package
 * time ({@see importDashSubtitles}, {@see hlsAddSubtitles}); this class also removes them afterwards.
 */
class ManifestEditor
{
    private const DASH_NS = 'urn:mpeg:dash:schema:mpd:2011';

    /**
     * Re-uploads must keep the manifest's Content-Type or the VOD edge's `secure_token` module
     * (which only rewrites bodies of these types) stops signing segment URLs → players 403. Flysystem
     * would otherwise sniff `text/xml`/`text/plain`. Mirrors what `s5cmd sync` sets by extension.
     */
    private const CONTENT_TYPES = [
        'dash' => 'application/dash+xml',
        'hls' => 'application/vnd.apple.mpegurl',
    ];

    /**
     * Drop a stream from every manifest that references it and delete its packaged segment tree.
     * Manifests are scoped per output ({@see \App\Models\Output::manifestFile}) and a stream can be
     * attached to more than one output (the same rendition reused across outputs that share a
     * config), so the relevant manifest set is gathered per output the stream actually belongs to —
     * never deleted outright as a file, only edited in place, or a cap still serving another
     * stream/output would vanish. Subtitles aren't pivoted to outputs (they're grafted into every
     * output's manifest at package time), so removal walks every output of the video instead. Call
     * before deleting the DB row so heights resolve.
     */
    public function removeStream(Video $video, Stream $stream): void
    {
        $disk = Storage::disk('s3');
        $ulid = $stream->ulid;
        $segmentsPath = $stream->segmentsPath($video);

        if ($stream->type === 'subtitle') {
            foreach ($video->outputs as $output) {
                foreach ($this->existingManifests($video, $output) as $manifest) {
                    $content = $disk->get($manifest['path']);
                    $edited = $manifest['format'] === 'dash'
                        ? $this->dashRemoveSubtitle($content, $ulid)
                        : $this->hlsRemoveSubtitle($content, $ulid);

                    if ($edited !== null && $edited !== $content) {
                        $disk->put($manifest['path'], $edited, ['ContentType' => self::CONTENT_TYPES[$manifest['format']]]);
                    }
                }
            }

            $disk->deleteDirectory($segmentsPath);

            return;
        }

        $height = $stream->type === 'video' ? (int) $stream->height : null;

        foreach ($stream->outputs as $output) {
            foreach ($this->existingManifests($video, $output) as $manifest) {
                ['cap' => $cap, 'format' => $format, 'path' => $path] = $manifest;

                if ($height !== null && $cap !== null && $cap < $height) {
                    continue; // this manifest caps below the rendition; it never listed it
                }

                $content = $disk->get($path);

                $edited = $format === 'dash'
                    ? $this->dashRemove($content, $ulid)
                    : $this->hlsRemove($content, $ulid);

                if ($edited !== null && $edited !== $content) {
                    $disk->put($path, $edited, ['ContentType' => self::CONTENT_TYPES[$format]]);
                }
            }
        }

        $disk->deleteDirectory($segmentsPath);
    }

    /**
     * Graft the packager-generated text AdaptationSet(s) from a throwaway subtitles MPD into a real
     * manifest's `<Period>`. Subtitles are packaged in a separate single-segment run
     * ({@see \App\Services\PackagerCommandBuilder::buildText}), so the imported set is *fragmented*
     * text (`SegmentTemplate`) that dashjs plays — a raw `<BaseURL>` VTT would make it deref null and
     * crash. Renumbers ids so they don't collide with the video/audio sets. Idempotent (skips a track
     * already present). Returns null if nothing changed. Labels come from the DB, not the manifest.
     */
    public function importDashSubtitles(string $masterXml, string $subsXml): ?string
    {
        [$doc, $xpath] = $this->loadMpd($masterXml);
        [$subsDoc, $subsXpath] = $this->loadMpd($subsXml);

        if (! $doc || ! $subsDoc) {
            return null;
        }

        $period = $xpath->query('//m:Period')->item(0);

        if (! $period instanceof DOMElement) {
            return null;
        }

        $nextSetId = $this->maxNumericId($xpath, '//m:AdaptationSet') + 1;
        $nextRepId = $this->maxNumericId($xpath, '//m:Representation') + 1;
        $changed = false;

        foreach ($subsXpath->query("//m:AdaptationSet[@contentType='text']") as $set) {
            $tpl = $subsXpath->query('.//m:SegmentTemplate', $set)->item(0);

            if (! $tpl instanceof DOMElement || ! preg_match('#^([^/]+)/#', $tpl->getAttribute('media'), $m)) {
                continue;
            }

            // Idempotent: this track's segments are already referenced as a text set in the master.
            if ($xpath->query("//m:AdaptationSet[@contentType='text']//m:SegmentTemplate[contains(@media, '{$m[1]}/')]")->length) {
                continue;
            }

            $imported = $doc->importNode($set, true);

            if (! $imported instanceof DOMElement) {
                continue;
            }

            $imported->setAttribute('id', (string) $nextSetId++);

            foreach (iterator_to_array($imported->getElementsByTagNameNS(self::DASH_NS, 'Representation')) as $rep) {
                $rep->setAttribute('id', (string) $nextRepId++);
            }

            $period->appendChild($imported);
            $changed = true;
        }

        return $changed ? $doc->saveXML() : null;
    }

    /** Largest numeric `id` attribute among the nodes matched by `$query`, or -1 if none. */
    private function maxNumericId(DOMXPath $xpath, string $query): int
    {
        $max = -1;

        foreach ($xpath->query($query) as $node) {
            if ($node instanceof DOMElement && is_numeric($id = $node->getAttribute('id'))) {
                $max = max($max, (int) $id);
            }
        }

        return $max;
    }

    /**
     * The manifest set actually present on S3 for one output: its own master plus one capped
     * manifest per non-max video height among its own video streams, in each format the packager
     * emitted. Probes S3 so DASH-only outputs (no `.m3u8`) and absent caps are skipped without
     * knowing the output's codecs.
     *
     * @return list<array{cap:?int,format:string,path:string}>
     */
    private function existingManifests(Video $video, Output $output): array
    {
        $output->loadMissing('streams');
        $disk = Storage::disk('s3');

        $heights = $output->streams
            ->where('type', 'video')
            ->pluck('height')
            ->filter()
            ->map(fn ($h) => (int) $h)
            ->unique()
            ->sort()
            ->values();

        $max = $heights->last();
        $caps = collect([null])->merge($heights->reject(fn ($h) => $h === $max)->values());

        $files = [];

        foreach ($caps as $cap) {
            foreach (['hls', 'dash'] as $format) {
                $path = "{$video->ulid}/".$output->manifestFile($format, $cap);

                if ($disk->exists($path)) {
                    $files[] = ['cap' => $cap, 'format' => $format, 'path' => $path];
                }
            }
        }

        return $files;
    }

    // --- Subtitle removal --------------------------------------------------

    /** Drop the text `<AdaptationSet>` whose segments live under `$ulid/`. Null if not present. */
    private function dashRemoveSubtitle(string $xml, string $ulid): ?string
    {
        [$doc, $xpath] = $this->loadMpd($xml);

        if (! $doc) {
            return null;
        }

        $tpl = $xpath->query(
            "//m:AdaptationSet[@contentType='text']//m:SegmentTemplate[contains(@media, '{$ulid}/')]"
        )->item(0);

        if (! $tpl instanceof DOMElement) {
            return null;
        }

        $set = $tpl->parentNode->parentNode; // SegmentTemplate → Representation → AdaptationSet

        if (! $set instanceof DOMElement) {
            return null;
        }

        $set->parentNode->removeChild($set);

        return $doc->saveXML();
    }

    /**
     * Drop the `#EXT-X-MEDIA:TYPE=SUBTITLES` line for `$ulid`'s playlist. If no other subtitle
     * groups remain, also strips the `SUBTITLES=` attribute from every `#EXT-X-STREAM-INF` line.
     * Null if nothing was removed.
     */
    private function hlsRemoveSubtitle(string $content, string $ulid): ?string
    {
        $playlist = "{$ulid}/index.m3u8";
        $lines = preg_split('/\R/', $content);
        $removedGroup = null;
        $out = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, '#EXT-X-MEDIA')
                && str_contains($line, 'TYPE=SUBTITLES')
                && str_contains($line, "URI=\"{$playlist}\"")) {
                if (preg_match('/GROUP-ID="([^"]+)"/', $line, $g)) {
                    $removedGroup = $g[1];
                }

                continue; // drop this EXT-X-MEDIA line
            }

            $out[] = $line;
        }

        if ($removedGroup === null) {
            return null;
        }

        $hasOtherSubs = collect($out)->contains(
            fn ($l) => str_starts_with($l, '#EXT-X-MEDIA') && str_contains($l, 'TYPE=SUBTITLES')
        );

        if (! $hasOtherSubs) {
            $out = array_map(
                fn ($l) => str_starts_with($l, '#EXT-X-STREAM-INF')
                    ? preg_replace('/,SUBTITLES="[^"]*"/', '', $l)
                    : $l,
                $out,
            );
        }

        return implode("\n", $out);
    }

    // --- DASH (.mpd) -------------------------------------------------------

    /** Remove the Representation packaged under `$ulid`, dropping its empty AdaptationSet or
     *  recomputing the video set's max dimensions. Returns null if the ULID isn't present. */
    private function dashRemove(string $xml, string $ulid): ?string
    {
        [$doc, $xpath] = $this->loadMpd($xml);

        if (! $doc) {
            return null;
        }

        $tpl = $this->segmentTemplate($xpath, $ulid);

        if (! $tpl) {
            return null;
        }

        $rep = $tpl->parentNode;
        $set = $rep->parentNode;
        $set->removeChild($rep);

        if ($xpath->query('m:Representation', $set)->length === 0) {
            $set->parentNode->removeChild($set);
        } elseif ($set instanceof DOMElement && $set->hasAttribute('maxWidth')) {
            $this->recomputeVideoMaxes($xpath, $set);
        }

        return $doc->saveXML();
    }

    /** @return array{0:?DOMDocument,1:?DOMXPath} */
    private function loadMpd(string $xml): array
    {
        $doc = new DOMDocument;
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;

        $prev = libxml_use_internal_errors(true);
        $ok = $doc->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (! $ok) {
            return [null, null];
        }

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('m', self::DASH_NS);

        return [$doc, $xpath];
    }

    private function segmentTemplate(DOMXPath $xpath, string $ulid): ?DOMElement
    {
        $node = $xpath->query("//m:SegmentTemplate[starts-with(@media, '{$ulid}/')]")->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private function recomputeVideoMaxes(DOMXPath $xpath, DOMElement $set): void
    {
        $maxW = 0;
        $maxH = 0;

        foreach ($xpath->query('m:Representation', $set) as $rep) {
            if (! $rep instanceof DOMElement) {
                continue;
            }

            $maxW = max($maxW, (int) $rep->getAttribute('width'));
            $maxH = max($maxH, (int) $rep->getAttribute('height'));
        }

        if ($maxW) {
            $set->setAttribute('maxWidth', (string) $maxW);
        }

        if ($maxH && $set->hasAttribute('maxHeight')) {
            $set->setAttribute('maxHeight', (string) $maxH);
        }
    }

    // --- HLS (.m3u8) -------------------------------------------------------

    /** Drop the variant for `$ulid`: a `#EXT-X-STREAM-INF` + its URI line (video) or the
     *  `#EXT-X-MEDIA` audio line. Returns null if nothing referenced the ULID. */
    private function hlsRemove(string $content, string $ulid): ?string
    {
        $lines = preg_split('/\R/', $content);
        $out = [];
        $hit = false;

        for ($i = 0; $i < count($lines); $i++) {
            $line = $lines[$i];
            $next = $lines[$i + 1] ?? '';

            if (str_starts_with($line, '#EXT-X-STREAM-INF') && str_starts_with(trim($next), "{$ulid}/")) {
                $i++; // also drop the following URI line
                $hit = true;

                continue;
            }

            if (str_starts_with($line, '#EXT-X-MEDIA') && str_contains($line, "URI=\"{$ulid}/")) {
                $hit = true;

                continue;
            }

            $out[] = $line;
        }

        return $hit ? implode("\n", $out) : null;
    }

    /**
     * Merge the `#EXT-X-MEDIA:TYPE=SUBTITLES` lines from a throwaway subtitles master (the separate
     * subtitle run, {@see \App\Services\PackagerCommandBuilder::buildText}) into the real master, and
     * tag every variant with the subtitle group. The lines are shaka's own (URI points at the kept
     * `{ulid}/index.m3u8`). Idempotent via the TYPE=SUBTITLES guard. Null if nothing merged.
     */
    public function hlsAddSubtitles(string $masterContent, string $subsMaster): ?string
    {
        if (str_contains($masterContent, 'TYPE=SUBTITLES')) {
            return null;
        }

        $media = [];
        $group = null;

        foreach (preg_split('/\R/', $subsMaster) as $line) {
            if (str_starts_with($line, '#EXT-X-MEDIA') && str_contains($line, 'TYPE=SUBTITLES')) {
                $media[] = $line;

                if (preg_match('/GROUP-ID="([^"]+)"/', $line, $g)) {
                    $group = $g[1];
                }
            }
        }

        if (empty($media) || $group === null) {
            return null;
        }

        $out = [];
        $inserted = false;

        foreach (preg_split('/\R/', $masterContent) as $line) {
            if (str_starts_with($line, '#EXT-X-STREAM-INF')) {
                if (! $inserted) {
                    array_push($out, ...$media);
                    $inserted = true;
                }

                if (! str_contains($line, 'SUBTITLES=')) {
                    $line .= ",SUBTITLES=\"{$group}\"";
                }
            }

            $out[] = $line;
        }

        return $inserted ? implode("\n", $out) : null;
    }
}
