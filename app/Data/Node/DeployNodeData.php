<?php

namespace App\Data\Node;

use App\Data\RequestData;
use App\Enums\NodeType;
use App\Models\Node;
use Spatie\LaravelData\Optional;

class DeployNodeData extends RequestData
{
    public function __construct(
        /**
         * Spare disks to format into a proxy's cache pool, as the panel listed them. An empty
         * list means none: keep the cache off the disks and in a docker volume.
         */
        public array|Optional|null $disks,
    ) {}

    public static function rules(): array
    {
        $node = request()->route('node');

        // A production proxy must say which disks to format, even when the answer is "none".
        // The service reads a missing list as "every spare disk", and a panel that dropped the
        // field after failing to read the host's inventory would otherwise wipe every disk it
        // had not been able to show. Development is exempt because there the deploy never
        // formats anything anyway (the controller turns an absent list into an empty one).
        $mustChoose = $node instanceof Node && $node->type === NodeType::PROXY && ! app()->isLocal();

        return [
            'disks' => $mustChoose ? ['present', 'array'] : ['nullable', 'array'],
            // A device path lands in a shell script on the node.
            'disks.*' => ['string', 'regex:#^/dev/[a-z0-9]+$#'],
        ];
    }
}
