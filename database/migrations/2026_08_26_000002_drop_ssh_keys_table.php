<?php

use App\Settings\AppSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SSH keys stop being a table with a per-node foreign key and become the single key in
 * AppSettings ({@see AppSettings}). The settings migration just before this one
 * copied the key in use, so nothing here is read back; the column and the table only go.
 *
 * Destructive on purpose: a second key never had a use outside rotation, which is now one action.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('nodes', 'ssh_key_id')) {
            // Two statements: sqlite (the test database) rebuilds the table to drop a foreign
            // key, and refuses to drop a column that a constraint still names in one go.
            Schema::table('nodes', fn (Blueprint $table) => $table->dropForeign(['ssh_key_id']));
            Schema::table('nodes', fn (Blueprint $table) => $table->dropColumn('ssh_key_id'));
        }

        Schema::dropIfExists('ssh_keys');
    }

    public function down(): void
    {
        if (! Schema::hasTable('ssh_keys')) {
            Schema::create('ssh_keys', function (Blueprint $table) {
                $table->id();
                $table->string('name', 50);
                $table->text('public_key');
                $table->text('private_key');
                $table->string('fingerprint');
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('nodes', 'ssh_key_id')) {
            Schema::table('nodes', function (Blueprint $table) {
                $table->foreignId('ssh_key_id')->nullable()->after('id')->constrained()->nullOnDelete();
            });
        }
    }
};
