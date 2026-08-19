<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\UppyS3Service;
use Aws\S3\S3Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

/*
 * Formerly extended tapp/laravel-uppy-s3-multipart-upload's controller; the package stopped at
 * Laravel 12 support, so the S3 multipart plumbing it provided lives inline here now.
 */
class MyCustomUppyController extends Controller
{
    protected S3Client $client;

    protected string $bucket;

    public function __construct(protected UppyS3Service $uppyService)
    {
        $this->client = Storage::disk('s3')->getClient();
        $this->bucket = config('filesystems.disks.s3.bucket');
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

            // Same window as the multipart part signer; hardcoding it here meant changing the
            // configured expiry only affected files large enough to go multipart.
            $signedRequest = $this->client->createPresignedRequest(
                $cmd,
                config('uppy-s3-multipart-upload.s3.presigned_url.expiry_time'),
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

        return response()->json(
            $this->listParts($request->input('key'), $uploadId)
        );
    }

    public function completeMultipartUpload(Request $request, string $uploadId)
    {
        $this->authorizeUploadKey($request);

        $result = $this->client->completeMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $request->input('key'),
            'UploadId' => $uploadId,
            'MultipartUpload' => [
                'Parts' => $request->input('parts'),
            ],
        ]);

        return response()->json([
            'location' => $result['Location'],
        ]);
    }

    public function abortMultipartUpload(Request $request, string $uploadId)
    {
        $this->authorizeUploadKey($request);

        $this->client->abortMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $request->input('key'),
            'UploadId' => $uploadId,
        ]);

        return response()->json([]);
    }

    public function signPartUpload(Request $request)
    {
        $this->authorizeUploadKey($request);

        $command = $this->client->getCommand('UploadPart', [
            'Bucket' => $this->bucket,
            'Key' => $request->input('key'),
            'UploadId' => $request->route('uploadId'),
            'PartNumber' => (int) $request->route('partNumber'),
        ]);

        $result = $this->client->createPresignedRequest(
            $command,
            config('uppy-s3-multipart-upload.s3.presigned_url.expiry_time'),
        );

        return response()->json([
            'url' => (string) $result->getUri(),
        ]);
    }

    /** S3 caps ListParts pages at 1000; follow the marker so huge files list completely. */
    private function listParts(string $key, string $uploadId): array
    {
        $parts = [];
        $marker = 0;

        do {
            $page = $this->client->listParts([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'PartNumberMarker' => $marker,
            ]);

            $parts = array_merge($parts, $page['Parts'] ?? []);
            $marker = $page['NextPartNumberMarker'] ?? 0;
        } while (! empty($page['IsTruncated']));

        return $parts;
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
                // Disabled templates are retired, not deleted (they cannot be deleted once a video
                // uses them), so they must not be selectable for a new upload either.
                Rule::exists('templates', 'ulid')->where(function ($query) use ($project) {
                    return $query->where('project_id', $project->id)->where('enabled', true);
                }),
            ],
            'metadata.externalUserId' => 'nullable|string|max:255',
            'metadata.externalResourceId' => 'nullable|string|max:255',
        ]);
    }
}
