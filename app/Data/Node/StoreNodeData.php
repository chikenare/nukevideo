<?php

namespace App\Data\Node;

use App\Data\RequestData;
use App\Models\Node;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;

class StoreNodeData extends RequestData
{
    public function __construct(
        public string $name,
        #[MapInputName(CamelCaseMapper::class)]
        public string $ipAddress,
        public string $type,
        public string|Optional $user,
        #[MapInputName(CamelCaseMapper::class)]
        public bool|Optional $isStorageServer,
        public ?string $hostname,
        #[MapInputName(CamelCaseMapper::class)]
        public ?int $sshKeyId,
        #[MapInputName(CamelCaseMapper::class)]
        public ?string $storageEndpoint,
    ) {}

    public static function rules(): array
    {
        return [
            'hostname' => 'nullable|max:255',
            'name' => 'required|string|max:255|unique:nodes,name',
            'user' => 'string|max:32',
            'ipAddress' => 'required|ip',
            'type' => 'required|string|in:worker,proxy',
            'sshKeyId' => 'nullable|exists:ssh_keys,id',
            'isStorageServer' => [
                'sometimes', 'boolean',
                function ($attribute, $value, $fail) {
                    if (filter_var($value, FILTER_VALIDATE_BOOLEAN) && Node::where('is_storage_server', true)->exists()) {
                        $fail('Storage server exists.');
                    }
                },
            ],
            // Full endpoint (e.g. http://10.0.0.5:9000) where this node's RustFS store is reachable.
            'storageEndpoint' => 'nullable|url',
        ];
    }
}
