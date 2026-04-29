<?php

namespace App\Services;

use App\Enums\VideoStatus;
use App\Models\User;
use App\Models\Video;
use Illuminate\Validation\ValidationException;

class VideoService
{
    public function update(string $ulid, array $data, User $user): Video
    {
        $video = $user->videos()->where('ulid', $ulid)->firstOrFail();
        $video->update($data);

        return $video;
    }

    public function destroy(string $ulid, User $user): void
    {
        $video = $user->videos()->where('ulid', $ulid)->firstOrFail();

        if (! in_array($video->status, [VideoStatus::COMPLETED->value, VideoStatus::FAILED->value])) {
            throw ValidationException::withMessages(['message' => 'You cannot delete a video if it is still in progress.'])->status(400);
        }

        $video->delete();
    }
}
