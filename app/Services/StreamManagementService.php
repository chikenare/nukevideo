<?php

namespace App\Services;

use App\Enums\VideoStatus;
use App\Models\Stream;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class StreamManagementService
{
    public function __construct(private ManifestEditor $manifests) {}

    public function update(string $ulid, array $data, User $user): Stream
    {
        $stream = $this->findOrFail($ulid, $user);
        $stream->update($data);

        // An audio track's label/language is written into the manifests; sync them once the video
        // is live. Subtitle labels are read live from the DB (sidecar VTT) and video has no label.
        if ($stream->type === 'audio'
            && $stream->video->status === VideoStatus::COMPLETED->value
            && (array_key_exists('name', $data) || array_key_exists('language', $data))) {
            $this->manifests->relabelAudio($stream->video, $stream);
        }

        return $stream;
    }

    public function destroy(string $ulid, User $user): void
    {
        $stream = $this->findOrFail($ulid, $user);
        $video = $stream->video;

        if (! in_array($video->status, [VideoStatus::COMPLETED->value, VideoStatus::FAILED->value])) {
            throw ValidationException::withMessages(['message' => 'You cannot delete any stream until the video is processed.'])->status(400);
        }

        if ($stream->type === 'video' && $video->streams()->where('type', 'video')->count() <= 1) {
            throw ValidationException::withMessages(['message' => 'You cannot delete the last video rendition.'])->status(400);
        }

        // A FAILED video has no packaged manifests; only COMPLETED ones need S3 surgery.
        if ($video->status === VideoStatus::COMPLETED->value) {
            if (in_array($stream->type, ['video', 'audio'], true)) {
                $this->manifests->removeStream($video, $stream);
            } elseif ($stream->type === 'subtitle') {
                Storage::disk('s3')->delete($stream->path);
            }
        }

        $stream->delete();
    }

    private function findOrFail(string $ulid, User $user): Stream
    {
        return Stream::with('video')
            ->whereHas('video', fn ($q) => $q->where('user_id', $user->id))
            ->where('ulid', $ulid)
            ->firstOrFail();
    }
}
