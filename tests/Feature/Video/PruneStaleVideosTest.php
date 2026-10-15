<?php

/**
 * `videos:prune` deletes a failed video together with its source, the user's only copy. The window
 * is what stands between a transient outage and permanent loss, so it is pinned here: a video
 * failed for days is still there to retry, and only one failed for over a week goes.
 */

use App\Enums\VideoStatus;
use App\Models\Project;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

function videoFailedHoursAgo(int $hoursAgo): Video
{
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $video = Video::create([
        'name' => "failed {$hoursAgo}h ago",
        'duration' => 10,
        'aspect_ratio' => '16:9',
        'status' => VideoStatus::FAILED->value,
        'user_id' => $user->id,
        'project_id' => $project->id,
    ]);

    Video::whereKey($video->id)->update(['updated_at' => now()->subHours($hoursAgo)]);

    return $video;
}

beforeEach(fn () => Bus::fake());

it('keeps a video that failed days ago so it can still be retried', function () {
    $video = videoFailedHoursAgo(6 * 24);

    $this->artisan('videos:prune')->assertSuccessful();

    expect(Video::find($video->id))->not->toBeNull();
});

it('deletes a video that has been failed for over a week', function () {
    $video = videoFailedHoursAgo(7 * 24 + 1);

    $this->artisan('videos:prune')->assertSuccessful();

    expect(Video::find($video->id))->toBeNull();
});
