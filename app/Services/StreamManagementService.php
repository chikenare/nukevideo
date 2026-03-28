<?php

namespace App\Services;

use App\Enums\VideoStatus;
use App\Models\Stream;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class StreamManagementService
{
    public function update(string $ulid, array $data, User $user): Stream
    {
        $stream = Stream::with('video')->where('ulid', $ulid)->firstOrFail();
        $video = $stream->video;

        if (! $video || $video->user_id !== $user->id) {
            throw ValidationException::withMessages(['message' => 'Not found'])->status(404);
        }

        $stream->update($data);
        return $stream;
    }

    public function destroy(string $ulid, User $user): void
    {
        $stream = Stream::with('video')->where('ulid', $ulid)->firstOrFail();
        $video = $stream->video;

        if (! $video || $video->user_id !== $user->id) {
            throw ValidationException::withMessages(['message' => 'Not found'])->status(404);
        }

        if (! in_array($video->status, [VideoStatus::COMPLETED->value, VideoStatus::FAILED->value])) {
            throw ValidationException::withMessages(['message' => 'You cannot delete any stream until the video is processed.'])->status(400);
        }

        $stream->delete();
    }
}
