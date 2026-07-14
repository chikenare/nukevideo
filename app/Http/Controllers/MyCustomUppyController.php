<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\UppyS3Service;
use Illuminate\Http\Request;
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

        $result = $this->client->createMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'ContentDisposition' => 'inline',
        ]);

        $this->uppyService->storeUploadMeta(
            $key,
            $this->uppyService->buildUploadMeta($project->user, $project, $request->all()),
        );

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

        $this->uppyService->storeUploadMeta(
            $key,
            $this->uppyService->buildUploadMeta($project->user, $project, $request->all()),
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

    /*
     * The package endpoints below act on any key/uploadId the caller supplies; scope them to
     * uploads the authenticated user actually started (their cached UploadMeta) so one tenant
     * can't complete, abort or list another tenant's in-flight upload.
     */

    public function getUploadedParts(Request $request, string $uploadId)
    {
        $this->authorizeUploadKey($request);

        return parent::getUploadedParts($request, $uploadId);
    }

    public function completeMultipartUpload(Request $request, string $uploadId)
    {
        $this->authorizeUploadKey($request);

        return parent::completeMultipartUpload($request, $uploadId);
    }

    public function abortMultipartUpload(Request $request, string $uploadId)
    {
        $this->authorizeUploadKey($request);

        return parent::abortMultipartUpload($request, $uploadId);
    }

    public function signPartUpload(Request $request)
    {
        $this->authorizeUploadKey($request);

        return parent::signPartUpload($request);
    }

    /** Runs once per signed part, so it stays a string compare against the cached meta — no queries. */
    private function authorizeUploadKey(Request $request): void
    {
        $request->validate(['key' => 'required|string']);

        $meta = $this->uppyService->getUploadMeta($request->input('key'));
        $actor = $request->user();

        $owns = $meta && ($actor instanceof Project
            ? $meta->project === $actor->ulid
            : $meta->user === $actor->ulid);

        abort_unless($owns, 403, 'This upload does not belong to you.');
    }

    /**
     * Uppy carries the project in the metadata (its requests skip our axios interceptor, so there is
     * no header). A project API key can only ever mean its own project; a user must own the one named.
     */
    private function resolveProject(Request $request): Project
    {
        $request->validate([
            'metadata.project' => 'required|ulid',
        ]);

        $ulid = $request->input('metadata.project');
        $actor = $request->user();

        if ($actor instanceof Project) {
            abort_unless($actor->ulid === $ulid, 403, 'This API key belongs to another project.');

            return $actor;
        }

        return $actor->projects()->where('ulid', $ulid)->firstOrFail();
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
