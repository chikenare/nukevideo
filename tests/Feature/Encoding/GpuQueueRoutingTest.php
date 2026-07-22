<?php

use App\Jobs\PrepareVideoJob;
use App\Models\Node;
use App\Models\Project;
use App\Models\Stream;
use App\Models\Template;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;

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

function assertAccelCapacity(Video $video): void
{
    $job = new PrepareVideoJob($video->id, 'original.mp4');
    (fn () => $this->assertAccelCapacity($video))->call($job);
}

it('fails fast when a GPU rendition has no matching active node', function () {
    assertAccelCapacity(videoWithRendition('av1_qsv'));
})->throws(RuntimeException::class, 'intel GPU worker');

it('does not accept an inactive or wrong-hardware node as capacity', function () {
    Node::create(['name' => 'gpu-off', 'ip_address' => '10.0.0.16', 'type' => 'worker', 'accel' => 'intel', 'is_active' => false]);
    Node::create(['name' => 'gpu-nv', 'ip_address' => '10.0.0.17', 'type' => 'worker', 'accel' => 'nvidia']);

    assertAccelCapacity(videoWithRendition('av1_qsv'));
})->throws(RuntimeException::class, 'intel GPU worker');

it('passes when a matching GPU node is active', function () {
    Node::create(['name' => 'gpu-01', 'ip_address' => '10.0.0.16', 'type' => 'worker', 'accel' => 'intel']);

    assertAccelCapacity(videoWithRendition('av1_qsv'));

    expect(true)->toBeTrue();
});

it('never gates CPU renditions on nodes', function () {
    assertAccelCapacity(videoWithRendition('libsvtav1'));

    expect(true)->toBeTrue();
});

it('names the missing hardware straight from the template', function () {
    $video = videoWithRendition('hevc_nvenc');

    expect($video->template->missingAccel())->toBe('nvidia');

    Node::create(['name' => 'gpu-nv', 'ip_address' => '10.0.0.17', 'type' => 'worker', 'accel' => 'nvidia']);

    expect($video->template->fresh()->missingAccel())->toBeNull();
});
