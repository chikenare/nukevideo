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
 * stream is removed or an audio track relabelled — without re-running the packager. Every stream is
 * packaged into a directory named by its ULID ({@see PackagerCommandBuilder}), so each variant is
 * located by that ULID in the manifests (HLS `URI`, DASH `SegmentTemplate@media`). Manifests are KB,
 * so this runs inline. Subtitles are sidecar VTT (served live from the DB) and never appear here.
 */
class ManifestEditor
{
    private const DASH_NS = 'urn:mpeg:dash:schema:mpd:2011';

    /**
     * Re-uploads must keep the manifest's Content-Type or the VOD edge's `secure_token` module
     * (which only rewrites bodies of these types) stops signing segment URLs → players 403. Flysystem
     * would otherwise sniff `text/xml`/`text/plain`. Mirrors what `aws s3 sync` sets by extension.
     */
    private const CONTENT_TYPES = [
        'dash' => 'application/dash+xml',
        'hls' => 'application/vnd.apple.mpegurl',
    ];

    /**
     * Drop a video/audio stream from every manifest that lists it and delete its segment tree.
     * Audio appears in all manifests; a video rendition only in the full master and caps ≥ its
     * height. A capped set whose cap equals the removed height becomes redundant (it would mirror the
     * next-lower cap) and is deleted outright. Call before deleting the DB row so heights resolve.
     */
    public function removeStream(Video $video, Stream $stream): void
    {
        if ($stream->type === 'subtitle') {
            return;
        }

        $disk = Storage::disk('s3');
        $ulid = $stream->ulid;
        $height = $stream->type === 'video' ? (int) $stream->height : null;

        foreach ($this->existingManifests($video) as $manifest) {
            ['cap' => $cap, 'format' => $format, 'path' => $path] = $manifest;

            if ($height !== null && $cap !== null) {
                if ($cap === $height) {
                    $disk->delete($path); // dedicated cap for this height is now redundant
                    continue;
                }
                if ($cap < $height) {
                    continue; // this manifest caps below the rendition; it never listed it
                }
            }

            $content = $disk->get($path);

            $edited = $format === 'dash'
                ? $this->dashRemove($content, $ulid)
                : $this->hlsRemove($content, $ulid);

            if ($edited !== null && $edited !== $content) {
                $disk->put($path, $edited, ['ContentType' => self::CONTENT_TYPES[$format]]);
            }
        }

        $disk->deleteDirectory("{$video->ulid}/{$ulid}");
    }

    /** Rewrite an audio track's display label/language across every manifest that carries it. */
    public function relabelAudio(Video $video, Stream $stream): void
    {
        if ($stream->type !== 'audio') {
            return;
        }

        $disk = Storage::disk('s3');

        foreach ($this->existingManifests($video) as $manifest) {
            $content = $disk->get($manifest['path']);

            $edited = $manifest['format'] === 'dash'
                ? $this->dashRelabel($content, $stream)
                : $this->hlsRelabel($content, $stream);

            if ($edited !== null && $edited !== $content) {
                $disk->put($manifest['path'], $edited, ['ContentType' => self::CONTENT_TYPES[$manifest['format']]]);
            }
        }
    }

    /**
     * The manifest set actually present on S3: the full master plus one capped manifest per
     * non-max video height, in each format the packager emitted. Probes S3 so DASH-only outputs
     * (no `.m3u8`) and absent caps are skipped without knowing the output's codecs.
     *
     * @return list<array{cap:?int,format:string,path:string}>
     */
    private function existingManifests(Video $video): array
    {
        $video->loadMissing('streams');
        $disk = Storage::disk('s3');

        $heights = $video->streams
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
                $path = "{$video->ulid}/".Output::manifestFile($format, $cap);

                if ($disk->exists($path)) {
                    $files[] = ['cap' => $cap, 'format' => $format, 'path' => $path];
                }
            }
        }

        return $files;
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

    /** Set the audio AdaptationSet's `lang` and a `<Label>` child. Returns null if absent. */
    private function dashRelabel(string $xml, Stream $stream): ?string
    {
        [$doc, $xpath] = $this->loadMpd($xml);

        if (! $doc) {
            return null;
        }

        $tpl = $this->segmentTemplate($xpath, $stream->ulid);

        if (! $tpl) {
            return null;
        }

        $set = $tpl->parentNode->parentNode;

        if (! $set instanceof DOMElement) {
            return null;
        }

        if ($stream->language) {
            $set->setAttribute('lang', $stream->language);
        }

        if ($stream->name) {
            $label = $xpath->query('m:Label', $set)->item(0)
                ?? $set->insertBefore($doc->createElementNS(self::DASH_NS, 'Label'), $set->firstChild);

            while ($label->firstChild) {
                $label->removeChild($label->firstChild);
            }

            $label->appendChild($doc->createTextNode($stream->name));
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

    /** Rewrite NAME/LANGUAGE on the audio `#EXT-X-MEDIA` line for `$ulid`. */
    private function hlsRelabel(string $content, Stream $stream): ?string
    {
        $lines = preg_split('/\R/', $content);
        $hit = false;

        foreach ($lines as $i => $line) {
            if (str_starts_with($line, '#EXT-X-MEDIA') && str_contains($line, "URI=\"{$stream->ulid}/")) {
                $line = $this->setHlsAttr($line, 'NAME', $stream->name);
                $line = $this->setHlsAttr($line, 'LANGUAGE', $stream->language);
                $lines[$i] = $line;
                $hit = true;
            }
        }

        return $hit ? implode("\n", $lines) : null;
    }

    private function setHlsAttr(string $line, string $key, ?string $value): string
    {
        if ($value === null || $value === '') {
            return $line;
        }

        $value = str_replace('"', '', $value);
        $attr = "{$key}=\"{$value}\""; // built outside the replacement to keep $/\ in the value literal
        $pattern = '/'.$key.'="[^"]*"/';

        if (preg_match($pattern, $line)) {
            return preg_replace_callback($pattern, fn () => $attr, $line);
        }

        return preg_replace_callback('/^#EXT-X-MEDIA:/', fn ($m) => $m[0].$attr.',', $line);
    }
}
