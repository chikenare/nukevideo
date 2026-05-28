<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\UppyS3Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Tapp\LaravelUppyS3MultipartUpload\Http\Controllers\UppyS3MultipartController;
use Throwable;

class MyCustomUppyController extends UppyS3MultipartController
{
    public function __construct(protected UppyS3Service $uppyService)
    {
        parent::__construct();
    }

    public function createMultipartUpload(Request $request)
    {
        $project = $this->resolveProject($request);

        $this->validateMetadata($request, $project);

        $key = $this->uppyService->generateKey($request->input('filename'));

        Cache::put(
            "upload_meta:{$key}",
            $this->uppyService->buildUploadMeta($request->user(), $project, $request->all()),
            now()->addHours(6),
        );

        $result = $this->client->createMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'ContentDisposition' => 'inline',
        ]);

        return response()
            ->json([
                'uploadId' => $result['UploadId'],
                'key' => $result['Key'],
            ]);
    }

    public function getUploadParameters(Request $request)
    {
        $project = $this->resolveProject($request);

        $this->validateMetadata($request, $project);

        $key = $this->uppyService->generateKey($request->input('filename'));

        Cache::put(
            "upload_meta:{$key}",
            $this->uppyService->buildUploadMeta($request->user(), $project, $request->all()),
            now()->addHours(6),
        );

        try {
            $cmd = $this->client->getCommand('PutObject', [
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);

            $signedRequest = $this->client->createPresignedRequest(
                $cmd,
                '+10 minutes'
            );
        } catch (Throwable $exception) {
            Log::error('S3 presigned URL generation failed', ['error' => $exception->getMessage()]);
            $status = method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500;

            return response()
                ->json([
                    'message' => $exception->getMessage(),
                ], $status);
        }

        return response()->json([
            'method' => 'PUT',
            'url' => (string) $signedRequest->getUri(),
            'key' => $key,
        ]);
    }

    private function resolveProject(Request $request): Project
    {
        $request->validate([
            'metadata.project' => 'required|ulid',
        ]);

        return $request->user()->projects()
            ->where('ulid', $request->input('metadata.project'))
            ->firstOrFail();
    }

    private function validateMetadata(Request $request, Project $project): void
    {
        $request->validate([
            'filename' => 'required|string|max:255',
            'metadata.template' => [
                'required',
                'ulid',
                Rule::exists('templates', 'ulid')->where(function ($query) use ($project) {
                    return $query->where('project_id', $project->id);
                }),
            ],
            'metadata.externalUserId' => 'nullable|string|max:255',
            'metadata.externalResourceId' => 'nullable|string|max:255',
        ]);
    }
}
