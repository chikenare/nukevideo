<?php

use App\Settings\NodeSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * The chunk store's address becomes one fleet-wide setting ({@see NodeSettings}),
 * replacing the `is_storage_server` flag and `storage_endpoint` URL on a node row that the schema
 * migration after this one drops. There is one store and every worker points at it, so a per-node
 * field only invited a second flag, or a URL typed differently from where the store listened.
 *
 * The current storage node's endpoint is carried over as `host:port`, the port dropped when it is
 * the default the setting assumes anyway.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $endpoint = Schema::hasColumn('nodes', 'storage_endpoint')
            ? DB::table('nodes')->where('is_storage_server', true)->value('storage_endpoint')
            : null;

        $host = (string) parse_url((string) $endpoint, PHP_URL_HOST);
        $port = parse_url((string) $endpoint, PHP_URL_PORT);

        $this->migrator->add('node.chunk_store_address', $host === '' || $port === null || $port === 9000 ? $host : "{$host}:{$port}");
    }

    public function down(): void
    {
        $this->migrator->delete('node.chunk_store_address');
    }
};
