<?php

use App\Support\WebVtt;

describe('repairs what ffmpeg mangles', function () {
    it('drops a blank line left inside a cue', function () {
        $vtt = "WEBVTT\n\n23:52.030 --> 23:55.650\n<b>Episode 2:\n\nFor Myself</b>\n\n23:57.000 --> 23:58.000\nNext\n";

        expect(WebVtt::sanitize($vtt))
            ->toBe("WEBVTT\n\n23:52.030 --> 23:55.650\n<b>Episode 2:\nFor Myself</b>\n\n23:57.000 --> 23:58.000\nNext\n");
    });

    it('drops a run of several blank lines inside a cue', function () {
        $vtt = "WEBVTT\n\n00:01.000 --> 00:02.000\nfirst\n\n\n\nlast\n";

        expect(WebVtt::sanitize($vtt))->toBe("WEBVTT\n\n00:01.000 --> 00:02.000\nfirst\nlast\n");
    });

    it('repairs a cue whose continuation is indented', function () {
        $vtt = "WEBVTT\n\n00:01.000 --> 00:02.000\ntitle\n   \n    indented tail\n";

        expect(WebVtt::sanitize($vtt))->toBe("WEBVTT\n\n00:01.000 --> 00:02.000\ntitle\n    indented tail\n");
    });
});

describe('leaves valid files alone', function () {
    it('returns a well-formed file byte-identical', function (string $vtt) {
        expect(WebVtt::sanitize($vtt))->toBe($vtt);
    })->with([
        'plain cues' => ["WEBVTT\n\n00:01.000 --> 00:02.000\nhello\n\n00:03.000 --> 00:04.000\nbye\n"],
        'hour timestamps' => ["WEBVTT\n\n01:23:52.030 --> 01:23:55.650\nhello\n\n02:00:00.000 --> 02:00:01.000\nbye\n"],
        'cue identifiers' => ["WEBVTT\n\nintro\n00:01.000 --> 00:02.000\nhello\n\noutro\n00:03.000 --> 00:04.000\nbye\n"],
        'NOTE blocks' => ["WEBVTT\n\nNOTE this is a comment\n\n00:01.000 --> 00:02.000\nhello\n"],
        'STYLE blocks' => ["WEBVTT\n\nSTYLE\n::cue { color: peachpuff; }\n\n00:01.000 --> 00:02.000\nhello\n"],
        'CRLF line endings' => ["WEBVTT\r\n\r\n00:01.000 --> 00:02.000\r\nhello\r\n"],
        'trailing blank lines' => ["WEBVTT\n\n00:01.000 --> 00:02.000\nhello\n\n\n"],
        'header only' => ["WEBVTT\n"],
        'empty' => [''],
    ]);

    it('keeps the separator when a cue payload merely mentions an arrow', function () {
        // `A --> B` is dialogue, not a timestamp: treating it as one would splice two cues together.
        $vtt = "WEBVTT\n\n00:01.000 --> 00:02.000\nA --> B\n\n00:03.000 --> 00:04.000\nbye\n";

        expect(WebVtt::sanitize($vtt))->toBe($vtt);
    });
});
