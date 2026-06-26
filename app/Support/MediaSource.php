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
}
