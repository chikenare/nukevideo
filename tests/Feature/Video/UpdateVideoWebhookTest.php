<?php

use App\Jobs\DispatchWebhookJob;
use App\Models\Output;
use App\Models\Project;
use App\Models\Template;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Storage::fake('s3');

    // Building the payload reads each output's chunk progress, and deleting a video clears it.
    // Only a COMPLETED output short-circuits that ({@see Output::progress}), and these are not.
    Redis::shouldReceive('hvals')->andReturn([]);
    Redis::shouldReceive('del')->andReturn(1);

    $this->user = User::factory()->create();
    $this->project = Project::factory()->for($this->user)->create([
        'settings' => ['webhookUrl' => 'https://example.test/hook'],
    ]);

    $template = Template::create([
        'name' => 'Template',
        'query' => [],
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
    ]);

    $this->video = Video::create([
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
        'template_id' => $template->id,
        'name' => 'Clip',
        'duration' => 10,
        'aspect_ratio' => '16:9',
        // FAILED rather than COMPLETED so the stream edits skip the manifest surgery: what is
        // under test is the delivery, not what ManifestEditor writes to S3.
        'status' => 'failed',
    ]);

    $output = Output::create(['video_id' => $this->video->id, 'status' => 'failed']);

    $make = fn (string $type, array $attributes) => $this->video->streams()->create([
        'path' => "{$this->video->ulid}/{$type}/".Str::random(8).'.mp4',
        'type' => $type,
        'meta' => [],
        ...$attributes,
    ]);

    $this->videoStream = $make('video', ['name' => 'Video', 'width' => 1920, 'height' => 800]);
    $this->audio = $make('audio', ['name' => 'Espanol', 'language' => 'es-419', 'channels' => 2]);

    $output->streams()->attach([$this->videoStream->id, $this->audio->id]);

    Sanctum::actingAs($this->user);
    $this->withHeader('X-Project-Ulid', $this->project->ulid);
});

/** The whole point of the event: every delivery carries the complete video, never a partial track. */
function assertVideoUpdatedPayload(Closure $extra): void
{
    Queue::assertPushed(DispatchWebhookJob::class, function (DispatchWebhookJob $job) use ($extra) {
        return $job->payload['event'] === 'video.updated'
            && $job->payload['data']->ulid === (string) test()->video->ulid
            && $extra($job->payload['data']);
    });
}

it('sends a webhook when the video itself is updated', function () {
    $this->putJson("/api/videos/{$this->video->ulid}", ['name' => 'Renamed'])->assertOk();

    assertVideoUpdatedPayload(fn ($data) => $data->name === 'Renamed');
});

it('does not send a webhook when the update changes nothing', function () {
    $this->putJson("/api/videos/{$this->video->ulid}", ['name' => 'Clip'])->assertOk();

    Queue::assertNotPushed(DispatchWebhookJob::class);
});

it('sends the whole video when one of its streams is edited', function () {
    $this->putJson("/api/streams/{$this->audio->ulid}", [
        'name' => 'Latin American Spanish',
        'language' => 'es-MX',
        'forced' => false,
    ])->assertOk();

    assertVideoUpdatedPayload(fn ($data) => collect($data->streams)
        ->contains(fn ($stream) => $stream->name === 'Latin American Spanish' && $stream->language === 'es-MX'));
});

it('sends the whole video when one of its streams is deleted', function () {
    $this->deleteJson("/api/streams/{$this->audio->ulid}")->assertOk();

    assertVideoUpdatedPayload(fn ($data) => collect($data->streams)
        ->doesntContain(fn ($stream) => $stream->ulid === (string) test()->audio->ulid));
});

it('stays quiet while the video is still being processed', function () {
    $this->video->update(['status' => 'running']);

    // What the probes and the packager do to every stream of a running video, dozens of times.
    $this->audio->update(['package_size' => 1024]);

    Queue::assertNotPushed(DispatchWebhookJob::class);
});

it('does not announce an update for the streams a video delete takes with it', function () {
    $this->video->streams()->create([
        'path' => "{$this->video->ulid}/original/source.mp4",
        'type' => 'original',
        'meta' => [],
    ]);

    $this->video->update(['external_resource_id' => 'post-1']);
    Queue::fake();

    $this->deleteJson("/api/videos/{$this->video->ulid}")->assertOk();

    Queue::assertPushed(DispatchWebhookJob::class, 1);
    Queue::assertPushed(fn (DispatchWebhookJob $job) => $job->payload['event'] === 'video.deleted');
});
