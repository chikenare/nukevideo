<?php

use App\Models\Project;
use App\Models\Template;
use App\Models\User;
use App\Models\Video;
use App\Services\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->for($this->user)->create();
});

/** Issues the key out of band: acting-as would shadow the Bearer token we want to exercise. */
function projectKey(Project $project): string
{
    return app(ApiTokenService::class)->regenerateProjectKey($project)->plainTextToken;
}

function videoIn(Project $project): Video
{
    $template = Template::create([
        'name' => 'Template',
        'query' => [],
        'user_id' => $project->user_id,
        'project_id' => $project->id,
    ]);

    return Video::create([
        'user_id' => $project->user_id,
        'project_id' => $project->id,
        'template_id' => $template->id,
        'name' => 'Clip',
        'duration' => 10,
        'aspect_ratio' => '16:9',
        'status' => 'completed',
    ]);
}

it('returns the plain-text key once and makes the project its tokenable', function () {
    Sanctum::actingAs($this->user);

    $key = $this->postJson("/api/projects/{$this->project->ulid}/api-key")
        ->assertOk()
        ->json('data.apiKey');

    expect($key['token'])->toStartWith($key['id'].'|');

    $token = PersonalAccessToken::find($key['id']);
    expect($token->tokenable)->toBeInstanceOf(Project::class)
        ->and($token->tokenable->id)->toBe($this->project->id);

    // Listing exposes the metadata but never the secret.
    expect($this->getJson('/api/projects')->json('data.0.apiKey'))->not->toHaveKey('token');
});

it('revokes the previous key when regenerating', function () {
    $old = projectKey($this->project);
    projectKey($this->project);

    expect($this->project->tokens()->count())->toBe(1);

    $this->withToken($old)->getJson('/api/videos')->assertUnauthorized();
});

it('resolves the project from the key without the X-Project-Ulid header', function () {
    $this->withToken(projectKey($this->project))
        ->getJson('/api/videos')
        ->assertOk();
});

it('rejects a key used against another project', function () {
    $key = projectKey($this->project);
    $other = Project::factory()->for($this->user)->create();

    $this->withToken($key)
        ->withHeader('X-Project-Ulid', $other->ulid)
        ->getJson('/api/videos')
        ->assertForbidden();
});

it('cannot reach a video of another project owned by the same user', function () {
    $key = projectKey($this->project);
    $foreign = videoIn(Project::factory()->for($this->user)->create());

    $this->withToken($key)->getJson("/api/videos/{$foreign->ulid}")->assertNotFound();
    $this->withToken($key)->deleteJson("/api/videos/{$foreign->ulid}")->assertNotFound();
});

it('reaches the videos of its own project', function () {
    $key = projectKey($this->project);
    $video = videoIn($this->project);

    $this->withToken($key)->getJson("/api/videos/{$video->ulid}")->assertOk();
});

it('cannot mint a playback link for an output of another project', function () {
    $key = projectKey($this->project);
    $foreign = videoIn(Project::factory()->for($this->user)->create());
    $output = $foreign->outputs()->create(['status' => 'completed']);

    // 404 = confined; a 422 (found, no formats) would mean the foreign output was reachable.
    $this->withToken($key)->postJson("/api/outputs/{$output->ulid}")->assertNotFound();
});

it('authenticates as the project, never as the owning user', function () {
    $this->withToken(projectKey($this->project))->getJson('/api/videos')->assertOk();

    expect(request()->user())->toBeInstanceOf(Project::class)
        ->and(request()->user()->id)->toBe($this->project->id);
});

it('cannot reach account, usage or admin endpoints', function () {
    $this->user->update(['is_admin' => true]);
    $key = projectKey($this->project);

    foreach (['/api/me', '/api/projects', '/api/tokens', '/api/nodes', '/api/usage', '/api/analytics'] as $uri) {
        $this->withToken($key)->getJson($uri)->assertForbidden();
    }

    $this->withToken($key)->postJson("/api/projects/{$this->project->ulid}/api-key")->assertForbidden();
});

it('does not let a user regenerate the key of a project they do not own', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->postJson("/api/projects/{$this->project->ulid}/api-key")->assertNotFound();
});
