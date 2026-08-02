<?php

use App\Jobs\PrepareVideoJob;
use App\Models\Node;
use App\Models\Project;
use App\Models\Stream;
use App\Models\Template;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('routes chunk queues by the stream codec hardware', function () {
    $stream = fn (string $codec) => (new Stream)->forceFill([
        'type' => 'video',
        'input_params' => ['video_codec' => $codec],
    ]);

    expect($stream('libx264')->encodeQueue())->toBe('video-processing')
        ->and($stream('libsvtav1')->encodeQueue())->toBe('video-processing')
        ->and($stream('av1_qsv')->encodeQueue())->toBe('video-processing-intel')
        ->and($stream('hevc_nvenc')->encodeQueue())->toBe('video-processing-nvidia');
});

function videoWithRendition(string $codec): Video
{
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $template = Template::create([
        'name' => 'Template',
        'query' => ['outputs' => [['video_codec' => $codec, 'variants' => [['width' => 1920, 'height' => 1080]]]]],
        'user_id' => $user->id,
        'project_id' => $project->id,
    ]);

    $video = Video::create([
        'user_id' => $user->id,
        'project_id' => $project->id,
        'template_id' => $template->id,
        'name' => 'Clip',
        'duration' => 10,
        'aspect_ratio' => '16:9',
        'status' => 'running',
    ]);

    $video->streams()->create([
        'name' => '1080p',
        'path' => "{$video->ulid}/video/rendition.mp4",
        'type' => 'video',
        'input_params' => ['video_codec' => $codec],
        'meta' => [],
    ]);

    return $video;
}

function assertEncodeCapacity(Video $video): void
{
    $job = new PrepareVideoJob($video->id, 'original.mp4');
    (fn () => $this->assertEncodeCapacity($video))->call($job);
}

it('fails fast when a GPU rendition has no matching active node', function () {
    assertEncodeCapacity(videoWithRendition('av1_qsv'));
})->throws(RuntimeException::class, 'intel worker');

it('does not accept an inactive or wrong-hardware node as capacity', function () {
    Node::create(['name' => 'gpu-off', 'ip_address' => '10.0.0.16', 'type' => 'worker', 'accel' => 'intel', 'is_active' => false]);
    Node::create(['name' => 'gpu-nv', 'ip_address' => '10.0.0.17', 'type' => 'worker', 'accel' => 'nvidia']);

    assertEncodeCapacity(videoWithRendition('av1_qsv'));
})->throws(RuntimeException::class, 'intel worker');

it('passes when a matching GPU node is active', function () {
    Node::create(['name' => 'gpu-01', 'ip_address' => '10.0.0.16', 'type' => 'worker', 'accel' => 'intel']);

    assertEncodeCapacity(videoWithRendition('av1_qsv'));

    expect(true)->toBeTrue();
});

it('fails fast when a CPU rendition has no CPU node, GPU nodes included', function () {
    Node::create(['name' => 'gpu-01', 'ip_address' => '10.0.0.16', 'type' => 'worker', 'accel' => 'intel']);

    assertEncodeCapacity(videoWithRendition('libsvtav1'));
})->throws(RuntimeException::class, 'cpu worker');

it('passes when a CPU node is active', function () {
    Node::create(['name' => 'cpu-01', 'ip_address' => '10.0.0.20', 'type' => 'worker']);

    assertEncodeCapacity(videoWithRendition('libsvtav1'));

    expect(true)->toBeTrue();
});

it('names the missing hardware straight from the template', function () {
    $video = videoWithRendition('hevc_nvenc');

    expect($video->template->missingCapacity())->toBe('nvidia');

    Node::create(['name' => 'gpu-nv', 'ip_address' => '10.0.0.17', 'type' => 'worker', 'accel' => 'nvidia']);

    expect($video->template->fresh()->missingCapacity())->toBeNull();
});

describe('handing the video to a node that has the hardware', function () {
    function handedOff(Video $video): bool
    {
        $job = new PrepareVideoJob($video->id, 'original.mp4');

        return (fn () => $this->handedToCapableNode($video))->call($job);
    }

    it('hops to another orchestration worker when this node lacks the template hardware', function (?string $nodeAccel) {
        Queue::fake();
        config(['ffmpeg.node_accel' => $nodeAccel]);

        expect(handedOff(videoWithRendition('av1_qsv')))->toBeTrue();

        Queue::assertPushed(PrepareVideoJob::class, fn (PrepareVideoJob $job) => $job->nodeHops === 1);
    })->with(['CPU-only node' => null, 'the other GPU family' => 'nvidia']);

    it('stays put when this node has the hardware, or when none is needed', function (string $codec, ?string $nodeAccel) {
        Queue::fake();
        config(['ffmpeg.node_accel' => $nodeAccel]);

        expect(handedOff(videoWithRendition($codec)))->toBeFalse();

        Queue::assertNothingPushed();
    })->with([
        'matching GPU node' => ['av1_qsv', 'intel'],
        'CPU template on a CPU node' => ['libsvtav1', null],
        'CPU template on a GPU node' => ['libsvtav1', 'intel'],
    ]);

    it('gives up hopping rather than leaving a video unprepared', function () {
        Queue::fake();
        config(['ffmpeg.node_accel' => null]);

        $video = videoWithRendition('av1_qsv');
        $job = new PrepareVideoJob($video->id, 'original.mp4', nodeHops: 3);

        expect((fn () => $this->handedToCapableNode($video))->call($job))->toBeFalse();

        Queue::assertNothingPushed();
    });
});
