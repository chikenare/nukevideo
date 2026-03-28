<?php

namespace App\Services;

use App\Models\User;

class UppyS3Service
{
    // The Uppy logic is heavily tied to the Tapp\LaravelUppyS3MultipartUpload package's internal state ($this->client, $this->bucket, etc.).
    // So extracting it completely to a generic Service is complex without passing the S3 client instance.
    // Given the implementation plan, we will keep the controller logic interacting with `$this->client`
    // but we can abstract the S3 parameter generation or just use the controller since it's an extension of the package controller.
    // Actually, looking at the code, it's mostly package overrides, so extracting to a service isn't strictly necessary for the AWS calls,
    // but we can create a service to abstract generating metadata and parsing Uppy parameters. Let's provide a basic helper.

    public function generateKey(string $filename): string
    {
        return FileService::generateKey($filename);
    }

    public function buildMetadata(User $user, array $input, array $query): array
    {
        return [
            'User' => $user->ulid,
            'Template' => $input['metadata']['template'] ?? null,
            'Filename' => $query['x-amz-meta-Filename'] ?? ($input['filename'] ?? ''),
        ];
    }
}
