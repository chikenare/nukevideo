<?php

namespace App\Data\Node;

use App\Data\RequestData;
use App\Enums\NodeType;
use App\Models\Node;
use App\Rules\ChunkStoreConfiguredRule;
use Illuminate\Validation\Rule;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;

class DeployNodeData extends RequestData
{
    public function __construct(
        /**
         * Spare disks to format whole into a proxy's cache pool, as the panel listed them. An
         * empty list means none: keep the cache off the disks and in a docker volume.
         */
        public array|Optional|null $disks,
        /**
         * System disks to share with the cache: each only gains a partition over its
         * unpartitioned space. A list of its own, because the node reads a disk's state again
         * when the deploy runs and a name alone would not say which of the two was agreed to
         * — a disk ticked for this must never reach the wipe because it has since stopped
         * looking like a system disk.
         */
        #[MapInputName(CamelCaseMapper::class)]
        public array $sharedDisks = [],
        /** Skip the drain: kill the worker's in-flight jobs (they redeliver ~31 min later). */
        public bool $force = false,
    ) {}

    public static function rules(): array
    {
        $node = request()->route('node');

        // A production proxy must say which disks to format, even when the answer is "none".
        // The service reads a missing list as "every spare disk" (never a shared one: those
        // are opt-in by name, so `sharedDisks` may be absent), and a panel that dropped the
        // field after failing to read the host's inventory would otherwise wipe every disk it
        // had not been able to show. Development is exempt because there the deploy never
        // formats anything anyway (the controller turns an absent list into an empty one).
        $mustChoose = $node instanceof Node && $node->type === NodeType::PROXY && ! app()->isLocal();

        return [
            'disks' => $mustChoose ? ['present', 'array'] : ['nullable', 'array'],
            // A device path lands in a shell script on the node.
            'disks.*' => ['string', 'regex:#^/dev/[a-z0-9]+$#'],
            'sharedDisks' => ['sometimes', 'array'],
            // One disk, one fate: named in both lists it would be asked for both.
            'sharedDisks.*' => ['string', 'regex:#^/dev/[a-z0-9]+$#', Rule::notIn((array) request()->input('disks', []))],
            'force' => ['sometimes', 'boolean'],
            'node' => [new ChunkStoreConfiguredRule($node instanceof Node ? $node : null)],
        ];
    }
}
