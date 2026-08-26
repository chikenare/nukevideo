<?php

namespace App\Settings;

use App\Services\SshKeyService;
use Spatie\LaravelSettings\Settings;

/**
 * Installation-wide settings that are neither a CDN nor a node concern.
 *
 * The SSH key lives here because there is exactly one: the panel connects to every node with the
 * same key, and a fleet of a thousand nodes is a thousand `authorized_keys` carrying the same
 * public line. Keys used to be a table with a per-node foreign key; nobody ever wanted a second
 * one except mid-rotation, and rotation is now a single action ({@see SshKeyService::rotate}).
 */
class AppSettings extends Settings
{
    /** OpenSSH private key, or '' before one is generated or imported. */
    public string $ssh_private_key;

    /** The matching public line (`ssh-ed25519 AAAA… comment`), for the nodes' authorized_keys. */
    public string $ssh_public_key;

    public string $ssh_fingerprint;

    public static function group(): string
    {
        return 'app';
    }

    public static function encrypted(): array
    {
        return ['ssh_private_key'];
    }
}
