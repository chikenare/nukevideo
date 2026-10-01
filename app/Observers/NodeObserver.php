<?php

namespace App\Observers;

use App\Enums\NodeType;
use App\Models\Node;
use App\Settings\NodeSettings;
use Illuminate\Support\Str;

class NodeObserver
{
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
        $settings = app(NodeSettings::class);

        if ($node->type === NodeType::WORKER && $settings->chunk_store_address === '') {
            $settings->chunk_store_address = $node->ip_address;
            $settings->save();
        }
    }

    /**
     * Deleting the node that held the chunk store leaves an address nothing answers on; empty, the
     * next worker created claims it, or another one is named in the node environment.
     */
    public function deleted(Node $node): void
    {
        $settings = app(NodeSettings::class);

        if (($settings->chunkStore()[0] ?? null) === $node->ip_address) {
            $settings->chunk_store_address = '';
            $settings->save();
        }
    }
}
