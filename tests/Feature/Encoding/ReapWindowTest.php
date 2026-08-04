<?php

use App\Models\Project;
use App\Models\Template;
use App\Models\User;
use App\Models\Video;
use App\Services\NodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function stalledVideo(int $minutesSinceHeartbeat): Video
{
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $template = Template::create([
        'name' => 'Template',
        'query' => [],
        'user_id' => $user->id,
        'project_id' => $project->id,
    ]);

    $video = Video::create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'template_id' => $template->id,
        'name' => 'Clip',
        'duration' => 600,
        'aspect_ratio' => '16:9',
        'status' => 'running',
    ]);

    $video->forceFill(['last_heartbeat_at' => now()->subMinutes($minutesSinceHeartbeat)])->save();

    return $video;
}

describe('videos:reap', function () {
    it('leaves a video alone while the queue can still re-deliver its lost job', function () {
        // A worker killed mid-chunk stops beating the heartbeat, but the queue hands that job to
        // another worker after retry_after and the batch finishes. Reaping inside that window
        // failed videos whose chunks were all encoded and waiting on one redelivery.
        $video = stalledVideo((int) (NodeService::QUEUE_RETRY_AFTER / 60));

        $this->artisan('videos:reap')->assertSuccessful();

        expect($video->fresh()->status)->toBe('running');
    });

    it('fails a video once the redelivery window has passed with no heartbeat', function () {
        $window = (int) ceil((NodeService::QUEUE_RETRY_AFTER + NodeService::WORKER_TIMEOUT) / 60);
        $video = stalledVideo($window + 5);

        $this->artisan('videos:reap')->assertSuccessful();

        expect($video->fresh()->status)->toBe('failed');
    });

    it('still honours an explicit threshold', function () {
        $video = stalledVideo(30);

        $this->artisan('videos:reap', ['--minutes' => 20])->assertSuccessful();

        expect($video->fresh()->status)->toBe('failed');
    });
});
