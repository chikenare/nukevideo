<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->project = Project::factory()->for($this->user)->create();

    Sanctum::actingAs($this->user);
});

it('serves project routes to a user who names the project with the header', function () {
    $this->withHeader('X-Project-Ulid', $this->project->ulid);

    $this->getJson('/api/videos')->assertOk();
    $this->getJson('/api/templates')->assertOk();
    $this->getJson('/api/activity-log')->assertOk();
});

it('refuses project routes when the user names no project', function () {
    $this->getJson('/api/videos')->assertStatus(400);
});

it('refuses a project the user does not own', function () {
    $foreign = Project::factory()->for(User::factory())->create();

    $this->withHeader('X-Project-Ulid', $foreign->ulid)
        ->getJson('/api/videos')
        ->assertNotFound();
});

it('still serves account routes, which need no project', function () {
    $this->getJson('/api/me')->assertOk();
    $this->getJson('/api/projects')->assertOk();
});
