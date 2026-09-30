<?php

use App\Models\User;
use App\Settings\NodeSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
    NodeSettings::fake(['environment' => "DOCKER_MEMORY=8g\n"]);
});

it('saves the global node environment', function () {
    $this->patchJson('/api/node-environment', ['environment' => 'DISABLE_PACKAGING=true'])
        ->assertOk()
        ->assertJsonPath('data.environment', 'DISABLE_PACKAGING=true');

    expect(app(NodeSettings::class)->environment)->toBe('DISABLE_PACKAGING=true');
});

it('can be emptied', function () {
    // An empty body arrives as null (ConvertEmptyStringsToNull): with the field required, the
    // last variable could never be removed from the global environment.
    $this->patchJson('/api/node-environment', ['environment' => ''])->assertOk();

    expect(app(NodeSettings::class)->environment)->toBe('');
});

it('is bounded like a node\'s own overrides', function () {
    $this->patchJson('/api/node-environment', ['environment' => str_repeat('A', 10001)])
        ->assertStatus(422);
});
