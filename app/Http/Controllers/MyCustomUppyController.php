<?php

namespace App\Http\Controllers;

use App\Services\FileService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Tapp\LaravelUppyS3MultipartUpload\Http\Controllers\UppyS3MultipartController;
use Throwable;

class MyCustomUppyController extends UppyS3MultipartController
{
    public function createMultipartUpload(Request $request)
    {
        $this->validateTemplate($request);

        $type = $request->input('type');
        $key = FileService::generateKey($request->input('filename'));

        $result = $this->client->createMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'ContentType' => $type,
            'ContentDisposition' => 'inline',
            'Metadata' => $this->buildMetadata($request)
        ]);
        return response()
            ->json([
                'uploadId' => $result['UploadId'],
                'key' => $result['Key'],
            ]);
    }

    public function getUploadParameters(Request $request)
    {
        $this->validateTemplate($request);

        $type = $request->input('type');
        $key = FileService::generateKey($request->input('filename'));

        $template = $request->input('metadata.template');

        try {
            $cmd = $this->client->getCommand('PutObject', [
                'Bucket' => $this->bucket,
                'Key' => $key,
                'ContentType' => $type,
                'Metadata' => $this->buildMetadata($request),
            ]);

            $signedRequest = $this->client->createPresignedRequest(
                $cmd,
                '+10 minutes'
            );
        } catch (Throwable $exception) {
            return response()
                ->json([
                    'message' => $exception->getMessage(),
                ], $exception->getStatusCode());
        }

        return response()->json([
            'method' => 'PUT',
            'url' => (string) $signedRequest->getUri(),
            'headers' => [
                'X-Amz-Meta-User' => $request->user()->uuid,
                'X-Amz-Meta-Template' => $template,
                'X-Amz-Meta-Filename' => $request->query('x-amz-meta-Filename') ?? $request->input('filename'),
                'Content-Type' => $type
            ],
            'key' => $key,
        ]);
    }

    private function buildMetadata(Request $request)
    {
        return [
            'User' => $request->user()->uuid,
            'Template' => $request->input('metadata.template'),
            'Filename' => $request->query('x-amz-meta-Filename') ?? $request->input('filename')
        ];
    }

    private function validateTemplate(Request $request)
    {
        $request->validate([
            'metadata.template' => [
                'required',
                'ulid',
                Rule::exists('templates', 'ulid')->where(function ($query) use ($request) {
                    return $query->where('user_id', $request->user()->id);
                }),
            ],
        ]);
    }
}
