<?php

use App\Jobs\DispatchWebhookJob;
use App\Models\Project;
use App\Models\Template;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create();
    $this->project = Project::factory()->for($this->user)->create([
        'settings' => ['webhookUrl' => 'https://example.test/hook'],
    ]);

    $this->template = Template::create([
        'name' => 'Template',
        'query' => [],
        'user_id' => $this->user->id,
        'project_id' => $this->project->id,
    ]);
});

function makeVideo(array $attributes = []): Video
{
    return Video::create([
        'user_id' => test()->user->id,
        'project_id' => test()->project->id,
        'template_id' => test()->template->id,
        'name' => 'Clip',
        'duration' => 10,
        'aspect_ratio' => '16:9',
        'status' => 'completed',
        ...$attributes,
    ]);
}

it('sends a webhook when a video with an external resource id is deleted', function () {
    $video = makeVideo(['external_resource_id' => 'post-1']);

    $video->delete();

    Queue::assertPushed(DispatchWebhookJob::class, function (DispatchWebhookJob $job) use ($video) {
        return $job->payload['event'] === 'video.deleted'
            && $job->payload['data']->ulid === (string) $video->ulid
            && $job->payload['data']->externalResourceId === 'post-1';
    });
});

it('does not send a webhook when the video has no external resource id', function () {
    makeVideo()->delete();

    Queue::assertNotPushed(DispatchWebhookJob::class);
});
