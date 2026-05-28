<?php

namespace App\Services;

use App\DTOs\UploadMeta;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class UppyS3Service
{
    public function generateKey(string $filename): string
    {
        return FileService::generateKey($filename);
    }

    public function buildUploadMeta(User $user, Project $project, array $input): UploadMeta
    {
        return UploadMeta::fromRequest($user, $project, $input);
    }

    public function getUploadMeta(string $key): ?UploadMeta
    {
        return Cache::get("upload_meta:{$key}");
    }

    public function forgetUploadMeta(string $key): void
    {
        Cache::forget("upload_meta:{$key}");
    }
}
