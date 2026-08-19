<?php

/**
 * The template list is a shelf the operator arranges: templates can be retired without being
 * deleted (a template a video references can never be deleted), duplicated to fork a working
 * encoding profile, and dragged into the order they are actually picked in.
 */

use App\Models\Project;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->for($this->user)->create();

    Sanctum::actingAs($this->user);
    $this->withHeader('X-Project-Ulid', $this->project->ulid);
});

function templateFor(Project $project, string $name = 'AV1'): Template
{
    return Template::create([
        'name' => $name,
        'query' => ['outputs' => []],
        'user_id' => $project->user_id,
        'project_id' => $project->id,
    ]);
}

it('creates templates enabled and lets them be retired without deleting them', function () {
    $template = templateFor($this->project);

    expect($template->enabled)->toBeTrue();

    $this->patchJson("/api/templates/{$template->ulid}", ['enabled' => false])
        ->assertOk()
        ->assertJsonPath('data.enabled', false);

    expect($template->fresh()->enabled)->toBeFalse();
});

it('refuses a disabled template on a new upload', function () {
    $template = templateFor($this->project);
    $template->update(['enabled' => false]);

    $this->getJson('/api/s3/params?'.http_build_query([
        'filename' => 'movie.mp4',
        'metadata' => ['project' => $this->project->ulid, 'template' => $template->ulid],
    ]))->assertStatus(422)->assertJsonValidationErrors('metadata.template');
});

it('lists every template by default and filters on demand', function () {
    $live = templateFor($this->project, 'Live');
    $retired = templateFor($this->project, 'Retired');
    $retired->update(['enabled' => false]);

    // A retired template stays listed: hiding it by default would leave no way to bring it back.
    expect($this->getJson('/api/templates')->json('data.*.name'))->toBe(['Live', 'Retired']);

    expect($this->getJson('/api/templates?enabled=true')->json('data.*.name'))->toBe(['Live'])
        ->and($this->getJson('/api/templates?enabled=false')->json('data.*.name'))->toBe(['Retired'])
        ->and($this->getJson('/api/templates?enabled=1')->json('data.*.name'))->toBe(['Live']);

    // A typo must not quietly come back as "the disabled ones".
    $this->getJson('/api/templates?enabled=maybe')->assertStatus(422);
});

it('duplicates a template into an independent copy', function () {
    $template = templateFor($this->project);
    $template->update(['keep_original' => true, 'enabled' => false]);

    $ulid = $this->postJson("/api/templates/{$template->ulid}/duplicate")
        ->assertOk()
        ->assertJsonPath('data.name', 'AV1 (copy)')
        ->assertJsonPath('data.keepOriginal', true)
        ->assertJsonPath('data.enabled', false)
        ->json('data.ulid');

    expect($ulid)->not->toBe($template->ulid);

    // Duplicating twice must not produce two templates with the same name.
    $this->postJson("/api/templates/{$template->ulid}/duplicate")
        ->assertOk()
        ->assertJsonPath('data.name', 'AV1 (copy 2)');
});

it('will not duplicate another project\'s template', function () {
    $other = Project::factory()->for(User::factory())->create();

    $this->postJson('/api/templates/'.templateFor($other)->ulid.'/duplicate')->assertNotFound();
});

it('lists templates in the stored order and persists a new one', function () {
    $first = templateFor($this->project, 'First');
    $second = templateFor($this->project, 'Second');
    $third = templateFor($this->project, 'Third');

    // Creation order is the initial order, not newest-first.
    expect($this->getJson('/api/templates')->json('data.*.name'))->toBe(['First', 'Second', 'Third']);

    $this->postJson('/api/templates/reorder', ['ulids' => [$third->ulid, $first->ulid, $second->ulid]])
        ->assertOk();

    expect($this->getJson('/api/templates')->json('data.*.name'))->toBe(['Third', 'First', 'Second']);
});

it('never reorders across projects', function () {
    $mine = templateFor($this->project, 'Mine');
    $other = Project::factory()->for(User::factory())->create();
    $theirs = templateFor($other, 'Theirs');

    $before = $theirs->fresh()->order_column;

    $this->postJson('/api/templates/reorder', ['ulids' => [$theirs->ulid, $mine->ulid]])
        ->assertStatus(422);

    expect($theirs->fresh()->order_column)->toBe($before);
});
