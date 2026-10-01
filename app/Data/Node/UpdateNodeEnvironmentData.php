<?php

namespace App\Data\Node;

use App\Data\RequestData;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;

class UpdateNodeEnvironmentData extends RequestData
{
    public function __construct(
        /** KEY=VALUE lines layered under every node's own `env`; empty clears it. */
        public ?string $environment,
        /** The chunk store's private `host` or `host:port`; empty until a worker is named. */
        #[MapInputName(CamelCaseMapper::class)]
        public string|Optional|null $chunkStoreAddress,
    ) {}

    public static function rules(): array
    {
        return [
            // Present but nullable: an empty field arrives as null, and it is how the last
            // variable is removed. Bounded like a node's own `env` (UpdateNodeData).
            'environment' => ['present', 'nullable', 'string', 'max:10000'],
            // A host, and a port if 9000 is taken, nothing else: it becomes the workers'
            // http://<host>:<port> endpoint and values in the deploy script. The host meets the
            // same criterion as a proxy's hostname (StoreNodeData), which a dotted IPv4 also meets.
            'chunkStoreAddress' => [
                'sometimes', 'nullable', 'string', 'max:255',
                'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*(:([1-9][0-9]{0,3}|[1-5][0-9]{4}|6[0-4][0-9]{3}|65[0-4][0-9]{2}|655[0-2][0-9]|6553[0-5]))?$/i',
            ],
        ];
    }
}
