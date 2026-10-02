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

it('shows and saves where the chunk store lives', function () {
    $this->patchJson('/api/node-environment', ['environment' => '', 'chunkStoreAddress' => 'chunks.internal'])
        ->assertOk()
        ->assertJsonPath('data.chunkStoreAddress', 'chunks.internal');

    $this->getJson('/api/node-environment')->assertJsonPath('data.chunkStoreAddress', 'chunks.internal');
});

it('takes a port with the address', function () {
    $this->patchJson('/api/node-environment', ['environment' => '', 'chunkStoreAddress' => '10.0.0.20:9009'])
        ->assertOk()
        ->assertJsonPath('data.chunkStoreAddress', '10.0.0.20:9009');
});

it('takes an address, not a URL', function (string $host) {
    // It lands in the workers' endpoint as http://<host>:9000 and in the deploy script.
    $this->patchJson('/api/node-environment', ['environment' => '', 'chunkStoreAddress' => $host])
        ->assertStatus(422);
})->with(['http://10.0.0.20:9000', '10.0.0.20; rm -rf /', 'chunks internal', '10.0.0.20:99999', '10.0.0.20:']);
