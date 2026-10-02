<?php

namespace App\Settings;

use App\Observers\NodeObserver;
use App\Services\NodeService;
use Spatie\LaravelSettings\Settings;

class NodeSettings extends Settings
{
    public string $environment;

    /**
     * Where the chunk store (the RustFS behind the `chunks` disk) is reached: `host` or `host:port`,
     * the host an IP or a DNS name on the workers' private network. One for the fleet; the worker
     * whose own address it is runs the store ({@see NodeService::chunkStoreVars}).
     * Claimed by the first worker created and cleared when that node is deleted
     * ({@see NodeObserver}).
     */
    public string $chunk_store_address;

    public const CHUNK_STORE_DEFAULT_PORT = 9000;

    /**
     * The chunk store's host and port, or null while none is set. The port is optional because
     * 9000 is the store's own and usually free; it is not on a host that already runs another
     * S3-compatible store, the development stack among them.
     *
     * @return array{0: string, 1: int}|null
     */
    public function chunkStore(): ?array
    {
        if ($this->chunk_store_address === '') {
            return null;
        }

        [$host, $port] = array_pad(explode(':', $this->chunk_store_address, 2), 2, null);

        return [$host, $port === null ? self::CHUNK_STORE_DEFAULT_PORT : (int) $port];
    }

    public static function group(): string
    {
        return 'node';
    }
}
