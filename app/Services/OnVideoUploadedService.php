<?php

namespace App\Services;

use App\DTOs\UploadMeta;
use App\Enums\VideoStatus;
use App\Models\Stream;
use App\Models\User;
use App\Models\Video;
use Exception;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Creates the video record the moment the upload webhook lands, so the user never loses
 * track of an upload even if later stages fail. Probe-derived fields (duration, aspect
 * ratio, rendition streams) are left empty and filled by {@see CreateVideoStreamsService}
 * once PrepareVideoJob has mirrored the source — probing main S3 here timed out.
 */
class OnVideoUploadedService
{
    private UploadMeta $meta;

    public function __construct(private UppyS3Service $uppyService) {}

    public function handle(string $key, int $size): void
    {
        // Idempotency first: a duplicate S3 delivery lands after the first run already ingested
        // AND forgot the meta, so requiring meta before this check would wrongly throw. Already
        // ingested means redelivery/retry — skip. The unique streams.path index covers the
        // concurrent race in the transaction below.
        if (Stream::where('path', $key)->exists()) {
            Log::info('Upload already ingested; skipping duplicate', ['key' => $key]);
            $this->uppyService->forgetUploadMeta($key);

            return;
        }

        $meta = $this->uppyService->getUploadMeta($key);

        if (! $meta) {
            throw new Exception("Upload metadata not found for key: {$key}");
        }

        $this->meta = $meta;

        [$user, $project, $template] = $this->resolveUserProjectAndTemplate();

        // The only template check possible without probing; fail before creating a doomed video.
        if (empty($template->query['outputs'] ?? [])) {
            throw new Exception("No outputs configured for template {$this->meta->template}");
        }

        try {
            $video = DB::transaction(function () use ($user, $project, $template, $key, $size) {
                $video = Video::create([
                    'user_id' => $user->id,
                    'project_id' => $project->id,
                    'template_id' => $template->id,
                    'name' => $this->meta->filename,
                    'status' => VideoStatus::PENDING->value,
                    'duration' => 0,
                    'aspect_ratio' => '',
                    'external_user_id' => $this->meta->externalUserId,
                    'external_resource_id' => $this->meta->externalResourceId,
                ]);

                $video->streams()->create([
                    'path' => $key,
                    'name' => 'Original',
                    'type' => 'original',
                    'file_size' => $size,
                    'meta' => [],
                ]);

                return $video;
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent delivery won the race to insert the original stream; it owns this upload.
            Log::info('Concurrent ingestion detected; skipping duplicate', ['key' => $key]);
            $this->uppyService->forgetUploadMeta($key);

            return;
        }

        activity('video')
            ->performedOn($video)
            ->causedBy($user)
            ->event('video_processing_started')
            ->log("Video queued for processing: {$video->name}");

        UsageService::record($user->id, 'upload_bytes', $size, $this->meta->externalUserId ?? '');

        $this->uppyService->forgetUploadMeta($key);

        WebhookDispatcher::forVideo('video.created', $video);
    }

    private function resolveUserProjectAndTemplate(): array
    {
        $user = User::where('ulid', $this->meta->user)->first();

        if (! $user) {
            throw new Exception("User with ulid {$this->meta->user} not found");
        }

        $project = $user->projects()->where('ulid', $this->meta->project)->first();

        if (! $project) {
            throw new Exception("Project with ulid {$this->meta->project} not found");
        }

        $template = $project->templates()->where('ulid', $this->meta->template)->first();

        if (! $template) {
            throw new Exception("Template with ulid {$this->meta->template} not found");
        }

        return [$user, $project, $template];
    }
}
