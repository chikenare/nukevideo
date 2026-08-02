<?php

namespace App\Support;

/** Helpers for feeding ffmpeg/ffprobe a source that may be a local file or a presigned URL. */
class MediaSource
{
    public static function isUrl(string $input): bool
    {
        return str_starts_with($input, 'http://') || str_starts_with($input, 'https://');
    }

    /** URLs can't be cheaply verified up front, so let ffmpeg surface a bad link. */
    public static function isReadable(string $input): bool
    {
        return self::isUrl($input) || file_exists($input);
    }

    /**
     * Input flags that make ffmpeg re-issue a ranged request when a long read over HTTP drops.
     * Without them a dropped connection reads as EOF: ffmpeg exits 0 and leaves an output that
     * covers only the bytes it managed to read.
     *
     * @return list<string>
     */
    public static function reconnectArguments(string $input): array
    {
        if (! self::isUrl($input)) {
            return [];
        }

        return [
            '-reconnect', '1',
            '-reconnect_streamed', '1',
            '-reconnect_on_network_error', '1',
            '-reconnect_on_http_error', '5xx',
            '-reconnect_delay_max', '30',
        ];
    }
}
