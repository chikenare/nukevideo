<?php

namespace App\Observers;

use App\Enums\NodeType;
use App\Models\Node;
use App\Services\CloudflareDns;
use App\Settings\NodeSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NodeObserver
{
    public function __construct(
        private NodeSettings $settings,
        private CloudflareDns $cloudflare,
    ) {}

    public function creating(Node $node): void
    {
        $node->uuid = Str::uuid()->toString();
    }

    /**
     * The first worker hosts the chunk store, so a new fleet needs nothing configured. Only while
     * there is none: the store holds the in-flight videos' sources and chunks, and moving it
     * because another node came along would strand them.
     */
    public function created(Node $node): void
    {
        if ($node->type === NodeType::WORKER && $this->settings->chunk_store_address === '') {
            $this->settings->chunk_store_address = $node->ip_address;
            $this->settings->save();
        }
    }

    /**
     * A proxy that changes hostname leaves a record behind under the old one, still pointing at
     * the node. The new name gets its record at the next deploy, which is also what moves the
     * Traefik router and the certificate over to it.
     */
    public function updated(Node $node): void
    {
        if ($node->type === NodeType::PROXY && $node->wasChanged('hostname')) {
            $this->forgetDnsRecord($node->getOriginal('hostname'), $node->getOriginal('ip_address'));
        }
    }

    /**
     * Deleting the node that held the chunk store leaves an address nothing answers on; empty, the
     * next worker created claims it, or another one is named in the node environment.
     */
    public function deleted(Node $node): void
    {
        if (($this->settings->chunkStore()[0] ?? null) === $node->ip_address) {
            $this->settings->chunk_store_address = '';
            $this->settings->save();
        }

        if ($node->type === NodeType::PROXY) {
            $this->forgetDnsRecord($node->hostname, $node->ip_address);
        }
    }

    /**
     * Never in the way of the save or the delete it follows: a record left behind in Cloudflare
     * is a line to remove by hand, and a node that cannot be deleted because an API is down is
     * a worse trade.
     */
    private function forgetDnsRecord(?string $hostname, string $ip): void
    {
        if (! $hostname) {
            return;
        }

        try {
            $this->cloudflare->remove($hostname, $ip);
        } catch (\Throwable $e) {
            Log::warning('Could not remove the Cloudflare record of a proxy node', ['hostname' => $hostname, 'error' => $e->getMessage()]);
        }
    }
}
