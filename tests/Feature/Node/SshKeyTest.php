<?php

/**
 * Payloads are camelCase: every mapped property of these Data objects carries
 * `#[MapInputName(CamelCaseMapper::class)]`, which is the spelling the panel sends.
 *
 * An SSH key is defined by its private half: it is what the panel connects with. The public half
 * and the fingerprint are derived from it on the server, so what the operator copies into a
 * node's `authorized_keys` is always the pair the panel presents — a pasted public key that did
 * not match used to be stored as if it did.
 */

use App\Models\Node;
use App\Models\SshKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;

uses(RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create(['is_admin' => true]));
});

it('generates an Ed25519 pair when no private key is given', function () {
    $data = $this->postJson('/api/ssh-keys', ['name' => 'panel-key'])->assertOk()->json('data');

    expect($data['publicKey'])->toStartWith('ssh-ed25519 ')
        ->and($data['publicKey'])->toEndWith(' panel-key')
        ->and($data['fingerprint'])->toMatch('/^([0-9a-f]{2}:){15}[0-9a-f]{2}$/')
        ->and($data)->not->toHaveKey('privateKey');

    // The stored private key is what the public half was derived from.
    $key = SshKey::find($data['id']);
    $derived = PublicKeyLoader::load($key->private_key)->getPublicKey()->toString('OpenSSH', ['comment' => 'panel-key']);

    expect($derived)->toBe($data['publicKey']);
});

it('derives the public key from an imported private key', function () {
    $private = EC::createKey('Ed25519');
    $expected = $private->getPublicKey()->toString('OpenSSH', ['comment' => 'imported']);

    $data = $this->postJson('/api/ssh-keys', [
        'name' => 'imported',
        'privateKey' => $private->toString('OpenSSH'),
    ])->assertOk()->json('data');

    expect($data['publicKey'])->toBe($expected);
});

it('rejects a private key it cannot load, and a public key posing as one', function () {
    $this->postJson('/api/ssh-keys', ['name' => 'x', 'privateKey' => 'not a key'])
        ->assertStatus(422)->assertJsonValidationErrors(['privateKey']);

    $public = EC::createKey('Ed25519')->getPublicKey()->toString('OpenSSH');

    $this->postJson('/api/ssh-keys', ['name' => 'x', 'privateKey' => $public])
        ->assertStatus(422)->assertJsonValidationErrors(['privateKey']);
});

it('assigns the only key to a node created without one', function () {
    $key = SshKey::create(['name' => 'only', 'public_key' => 'ssh-ed25519 AAAA only', 'private_key' => 'x', 'fingerprint' => 'ff']);

    $data = $this->postJson('/api/nodes', [
        'name' => 'worker-1', 'ipAddress' => '10.0.0.9', 'type' => 'worker', 'user' => 'root',
    ])->assertOk()->json('data');

    expect($data['sshKeyId'])->toBe($key->id);
});

it('leaves a node keyless when there are several keys and none was named', function () {
    foreach (['a', 'b'] as $name) {
        SshKey::create(['name' => $name, 'public_key' => "ssh-ed25519 AAAA {$name}", 'private_key' => 'x', 'fingerprint' => 'ff']);
    }

    $data = $this->postJson('/api/nodes', [
        'name' => 'worker-1', 'ipAddress' => '10.0.0.9', 'type' => 'worker', 'user' => 'root',
    ])->assertOk()->json('data');

    // The choice is real with two keys; guessing would connect with the wrong one.
    expect($data['sshKeyId'])->toBeNull()
        ->and(Node::first()->ssh_key_id)->toBeNull();
});
