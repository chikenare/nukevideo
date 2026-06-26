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
        $stream = $this->findOrFail($ulid, $user);
        $stream->update($data);

        return $stream;
    }

    public function destroy(string $ulid, User $user): void
    {
        $stream = $this->findOrFail($ulid, $user);

        if (! in_array($stream->video->status, [VideoStatus::COMPLETED->value, VideoStatus::FAILED->value])) {
            throw ValidationException::withMessages(['message' => 'You cannot delete any stream until the video is processed.'])->status(400);
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
