<?php

namespace App\Services;

use Illuminate\Support\Str;

class FileService
{
    public static function generateKey(string $name)
    {
        // The client controls $name; the extension ends up in storage keys and worker file
        // paths, so anything beyond alphanumerics is stripped.
        $fileExtension = preg_replace('/[^A-Za-z0-9]/', '', pathinfo($name, PATHINFO_EXTENSION));
        $folder = config('uppy-s3-multipart-upload.s3.bucket.folder') ? config('uppy-s3-multipart-upload.s3.bucket.folder').'/' : '';
        $key = $folder.Str::ulid().'.'.$fileExtension;

        return $key;
    }
}
