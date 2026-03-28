<?php

namespace App\Http\Controllers;

use App\Services\UppyS3Service;
use Illuminate\Http\Request;
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
        $request->validate([
            'type' => 'required|string|starts_with:video/,audio/',
            'metadata.template' => [
                'required',
                'ulid',
                Rule::exists('templates', 'ulid')->where(function ($query) use ($request) {
                    return $query->where('user_id', $request->user()->id);
                }),
            ],
        ]);

        $type = $request->input('type');
        $key = $this->uppyService->generateKey($request->input('filename'));

        $result = $this->client->createMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'ContentType' => $type,
            'ContentDisposition' => 'inline',
            'Metadata' => $this->uppyService->buildMetadata($request->user(), $request->all(), $request->query()),
        ]);

        return response()
            ->json([
                'uploadId' => $result['UploadId'],
                'key' => $result['Key'],
            ]);
    }

    public function getUploadParameters(Request $request)
    {
        $request->validate([
            'type' => 'required|string|starts_with:video/,audio/',
            'metadata.template' => [
                'required',
                'ulid',
                Rule::exists('templates', 'ulid')->where(function ($query) use ($request) {
                    return $query->where('user_id', $request->user()->id);
                }),
            ],
        ]);

        $type = $request->input('type');
        $key = $this->uppyService->generateKey($request->input('filename'));

        $template = $request->input('metadata.template');

        try {
            $cmd = $this->client->getCommand('PutObject', [
                'Bucket' => $this->bucket,
                'Key' => $key,
                'ContentType' => $type,
                'Metadata' => $this->uppyService->buildMetadata($request->user(), $request->all(), $request->query()),
            ]);

            $signedRequest = $this->client->createPresignedRequest(
                $cmd,
                '+10 minutes'
            );
        } catch (Throwable $exception) {
            $status = method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500;

            return response()
                ->json([
                    'message' => $exception->getMessage(),
                ], $status);
        }

        return response()->json([
            'method' => 'PUT',
            'url' => (string) $signedRequest->getUri(),
            'headers' => [
                'X-Amz-Meta-User' => $request->user()->ulid,
                'X-Amz-Meta-Template' => $template,
                'X-Amz-Meta-Filename' => $request->query('x-amz-meta-Filename') ?? $request->input('filename'),
                'Content-Type' => $type,
            ],
            'key' => $key,
        ]);
    }
}
