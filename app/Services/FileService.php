<?php

namespace App\Services;

use Illuminate\Support\Str;

class FileService
{
    public static function generateKey(string $name)
    {
        $fileExtension = pathinfo($name, PATHINFO_EXTENSION);
        $folder = config('uppy-s3-multipart-upload.s3.bucket.folder') ? config('uppy-s3-multipart-upload.s3.bucket.folder') . '/' : '';
        $key = $folder . Str::ulid() . '.' . $fileExtension;

        return $key;
    }
}
