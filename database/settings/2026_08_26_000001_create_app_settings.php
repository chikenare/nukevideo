<?php

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * The single SSH key, as a setting. It seeds itself from the `ssh_keys` table this replaces —
 * preferring the key the most nodes point at, since that is the one whose public half is
 * installed on the fleet — so an existing installation keeps its access across the upgrade. The
 * table itself is dropped by the schema migration that follows this one.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        [$private, $public, $fingerprint] = $this->keyInUse();

        $this->migrator->addEncrypted('app.ssh_private_key', $private);
        $this->migrator->add('app.ssh_public_key', $public);
        $this->migrator->add('app.ssh_fingerprint', $fingerprint);
    }

    public function down(): void
    {
        $this->migrator->delete('app.ssh_private_key');
        $this->migrator->delete('app.ssh_public_key');
        $this->migrator->delete('app.ssh_fingerprint');
    }

    /** @return array{string, string, string} */
    private function keyInUse(): array
    {
        if (! Schema::hasTable('ssh_keys')) {
            return ['', '', ''];
        }

        $keys = DB::table('ssh_keys')->get();

        if ($keys->isEmpty()) {
            return ['', '', ''];
        }

        $usage = Schema::hasColumn('nodes', 'ssh_key_id')
            ? DB::table('nodes')->whereNotNull('ssh_key_id')->selectRaw('ssh_key_id, count(*) as n')->groupBy('ssh_key_id')->pluck('n', 'ssh_key_id')
            : collect();

        $key = $keys->sortByDesc(fn ($k) => [$usage[$k->id] ?? 0, -$k->id])->first();

        // The table stored the private key through Laravel's `encrypted` cast.
        $private = $key->private_key;
        try {
            $private = Crypt::decryptString($private);
        } catch (Throwable) {
            // Stored in the clear (a hand-inserted row): keep it as-is.
        }

        return [$private, (string) $key->public_key, (string) $key->fingerprint];
    }
};
