<?php

namespace App\Services;

use Illuminate\Support\Str;

class FileService
{
    /**
     * Longest extension a key may carry. The filename is capped at 255 characters, every one of
     * which can legally be extension, and the finished key has to fit `streams.path` — a
     * VARCHAR(255) whose unique index is what makes ingestion idempotent. Without the cap S3
     * accepts the whole upload and the webhook only then dies writing the row, rolling the video
     * back with the file already in the bucket.
     */
    private const MAX_EXTENSION_LENGTH = 10;

    public static function generateKey(string $name)
    {
        // The client controls $name; the extension ends up in storage keys and worker file
        // paths, so anything beyond alphanumerics is stripped.
        $fileExtension = substr(
            (string) preg_replace('/[^A-Za-z0-9]/', '', pathinfo($name, PATHINFO_EXTENSION)),
            0,
            self::MAX_EXTENSION_LENGTH,
        );
        $folder = config('uppy-s3-multipart-upload.s3.bucket.folder') ? config('uppy-s3-multipart-upload.s3.bucket.folder').'/' : '';
        $key = $folder.Str::ulid().'.'.$fileExtension;

        return $key;
    }
}
