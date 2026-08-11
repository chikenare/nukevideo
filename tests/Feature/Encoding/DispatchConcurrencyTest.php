<?php

/**
 * One video already fans out across every worker — that is what chunking buys. So the cap on how
 * many videos run at once is not about parallelism, it is about how deep the shared FIFO queue
 * gets: dispatch several together and the shortest waits out the longest one's entire fan-out,
 * with nothing beating its heartbeat, until `videos:reap` mistakes the wait for a dead worker.
 */

use App\Enums\VideoStatus;
use App\Jobs\PrepareVideoJob;
use App\Models\Node;
use App\Models\Project;
use App\Models\Template;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    Node::create(['name' => 'cpu-01', 'ip_address' => '10.0.0.20', 'type' => 'worker']);

    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $this->template = Template::create([
        'name' => 'Template',
        'query' => ['outputs' => [['video_codec' => 'libx264', 'variants' => [['width' => 1280, 'height' => 720]]]]],
        'user_id' => $user->id,
        'project_id' => $project->id,
    ]);

    $this->makeVideo = function (string $status = 'pending') use ($user, $project) {
        $video = Video::create([
            'user_id' => $user->id,
            'project_id' => $project->id,
            'template_id' => $this->template->id,
            'name' => 'Clip',
            'duration' => 60,
            'aspect_ratio' => '16:9',
            'status' => $status,
        ]);

        $video->streams()->create([
            'path' => "{$video->ulid}/source/original.mp4",
            'type' => 'original',
            'name' => 'Original',
            'meta' => [],
        ]);

        return $video;
    };
});

it('dispatches only as many videos as the configured concurrency', function () {
    config(['nuke.video.concurrent' => 2]);

    collect(range(1, 5))->each(fn () => ($this->makeVideo)());

    $this->artisan('videos:dispatch')->assertSuccessful();

    Queue::assertPushed(PrepareVideoJob::class, 2);
    expect(Video::where('status', VideoStatus::RUNNING->value)->count())->toBe(2)
        ->and(Video::where('status', VideoStatus::PENDING->value)->count())->toBe(3);
});

it('counts what is already in flight against the same cap', function () {
    config(['nuke.video.concurrent' => 2]);

    ($this->makeVideo)('running');
    ($this->makeVideo)('uploading');
    ($this->makeVideo)();

    $this->artisan('videos:dispatch')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('ignores the node count, which says nothing about queue depth', function () {
    // Ten nodes do not mean ten videos should be queued at once — they all drain one list.
    collect(range(1, 9))->each(fn (int $i) => Node::create([
        'name' => "cpu-{$i}", 'ip_address' => "10.0.1.{$i}", 'type' => 'worker',
    ]));

    config(['nuke.video.concurrent' => 1]);

    collect(range(1, 4))->each(fn () => ($this->makeVideo)());

    $this->artisan('videos:dispatch')->assertSuccessful();

    Queue::assertPushed(PrepareVideoJob::class, 1);
});

it('dispatches nothing at all while no worker node is active', function () {
    Node::query()->update(['is_active' => false]);

    ($this->makeVideo)();

    $this->artisan('videos:dispatch')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('takes the oldest waiting video first', function () {
    config(['nuke.video.concurrent' => 1]);

    $newer = ($this->makeVideo)();
    $older = ($this->makeVideo)();
    $older->forceFill(['created_at' => now()->subDay()])->save();

    $this->artisan('videos:dispatch')->assertSuccessful();

    expect($older->fresh()->status)->toBe('running')
        ->and($newer->fresh()->status)->toBe('pending');
});
