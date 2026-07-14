<?php

namespace App\Services;

use App\Enums\VideoStatus;
use App\Models\Project;
use App\Models\Video;
use Illuminate\Validation\ValidationException;

class VideoService
{
    public function update(string $ulid, array $data, Project $project): Video
    {
        $video = $project->videos()->where('ulid', $ulid)->firstOrFail();
        $video->update($data);

        return $video;
    }

    public function destroy(string $ulid, Project $project): void
    {
        $video = $project->videos()->where('ulid', $ulid)->firstOrFail();

        if (! in_array($video->status, [VideoStatus::COMPLETED->value, VideoStatus::FAILED->value])) {
            throw ValidationException::withMessages(['message' => 'You cannot delete a video if it is still in progress.'])->status(400);
        }

        $video->delete();
    }
}
