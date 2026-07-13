<?php

use App\Models\Project;
use App\Models\Template;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->for($this->user)->create();

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
        'status' => 'completed',
        'external_user_id' => 'user-1',
        'external_resource_id' => 'post-1',
    ]);

    Sanctum::actingAs($this->user);
});

it('updates the external ids', function () {
    $this->putJson("/api/videos/{$this->video->ulid}", [
        'name' => 'Clip',
        'externalUserId' => 'user-2',
        'externalResourceId' => 'post-2',
    ])->assertOk()
        ->assertJsonPath('data.externalUserId', 'user-2')
        ->assertJsonPath('data.externalResourceId', 'post-2');
});

it('clears the external ids when sent as null', function () {
    $this->putJson("/api/videos/{$this->video->ulid}", [
        'name' => 'Clip',
        'externalUserId' => null,
        'externalResourceId' => null,
    ])->assertOk()
        ->assertJsonPath('data.externalUserId', null)
        ->assertJsonPath('data.externalResourceId', null);
});

it('keeps the external ids when they are not sent', function () {
    $this->putJson("/api/videos/{$this->video->ulid}", ['name' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed')
        ->assertJsonPath('data.externalUserId', 'user-1')
        ->assertJsonPath('data.externalResourceId', 'post-1');
});
