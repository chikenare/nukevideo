<?php

namespace App\Rules;

use App\Enums\NodeType;
use App\Models\Node;
use App\Settings\NodeSettings;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A worker deploy needs the chunk store's address ({@see NodeSettings::chunkStore()}). Checked in
 * the request because the deploy itself runs queued: refused there, it only failed later in the
 * operation's log, where nobody was looking yet. Proxies never use the store.
 */
class ChunkStoreConfiguredRule implements ValidationRule
{
    /** Runs although the request carries no such field: the node comes from the route. */
    public bool $implicit = true;

    /** The node from the route; null when the value is the list of node ids of a fleet deploy. */
    public function __construct(private ?Node $node = null) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (app(NodeSettings::class)->chunkStore()) {
            return;
        }

        $deploysWorker = $this->node
            ? $this->node->type === NodeType::WORKER
            : is_array($value) && Node::whereKey($value)->where('type', NodeType::WORKER)->exists();

        if ($deploysWorker) {
            $fail('No chunk store configured. Set its address under Nodes → Environment before deploying a worker.');
        }
    }
}
