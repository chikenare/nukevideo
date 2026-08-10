<?php

namespace App\Support;

/**
 * Repairs the WebVTT that ffmpeg writes for us. An ASS/SSA payload authored with a double line
 * break (`\N\N` — the title-card style fansubs use for episode titles) is muxed verbatim, so the
 * cue reaches disk carrying a BLANK LINE INSIDE it:
 *
 *     23:52.030 --> 23:55.650
 *     <b>Episode 2:
 *                                <- ends the cue as far as any parser is concerned
 *     For Myself</b>
 *
 * A blank line terminates a cue in WebVTT, so shaka reads the remainder as a new block, fails to
 * classify it ("Failed to determine block classification") and aborts the run with PARSER_FAILURE.
 * ffmpeg's webvtt encoder is a copy of its SRT one, where the sequence number re-anchors a parser
 * after a stray blank line; WebVTT has no such anchor, so the same output is simply invalid.
 *
 * We anchor on the timestamp line — the only unambiguous marker the format has — and treat
 * everything else as payload, which is the reading the spec intends. Conservative by construction:
 * a blank line is only dropped when what follows cannot start a block, and a well-formed file comes
 * back byte-identical.
 */
class WebVtt
{
    /** `[HH:]MM:SS.mmm -->`, strict so a payload that merely mentions `-->` is not read as a cue. */
    private const TIMING = '/^(?:\d{2,}:)?[0-5]\d:[0-5]\d\.\d{3}\s+-->/';

    private const HEADER_BLOCK = '/^(?:NOTE|STYLE|REGION)\b/';

    public static function sanitize(string $vtt): string
    {
        $lines = preg_split('/\R/', $vtt) ?: [];
        $count = count($lines);
        $kept = [];

        for ($i = 0; $i < $count; $i++) {
            if (trim($lines[$i]) !== '') {
                $kept[] = $lines[$i];

                continue;
            }

            $next = $i;

            while ($next < $count && trim($lines[$next]) === '') {
                $next++;
            }

            // Verbatim when the run separates real blocks (or trails the file); dropped when the cue
            // simply continues past it.
            if ($next >= $count || self::startsBlock($lines, $next)) {
                for ($blank = $i; $blank < $next; $blank++) {
                    $kept[] = $lines[$blank];
                }
            }

            $i = $next - 1;
        }

        // Nothing dropped: hand back the input rather than re-joining it, which would rewrite CRLF.
        return count($kept) === $count ? $vtt : implode("\n", $kept);
    }

    /**
     * Whether line `$i` opens a block a blank line may legitimately precede: a cue (timestamp, or an
     * identifier on the line above one) or a header block.
     *
     * @param  list<string>  $lines
     */
    private static function startsBlock(array $lines, int $i): bool
    {
        return preg_match(self::TIMING, $lines[$i]) === 1
            || preg_match(self::HEADER_BLOCK, $lines[$i]) === 1
            || str_starts_with($lines[$i], 'WEBVTT')
            || preg_match(self::TIMING, $lines[$i + 1] ?? '') === 1;
    }
}
