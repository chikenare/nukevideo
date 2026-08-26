<?php

namespace App\Data\Node;

use App\Data\RequestData;
use App\Models\Node;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;

class UpdateNodeData extends RequestData
{
    public function __construct(
        public string|Optional $name,
        public string|Optional|null $user,
        #[MapInputName(CamelCaseMapper::class)]
        public string|Optional $ipAddress,
        public string|Optional|null $hostname,
        #[MapInputName(CamelCaseMapper::class)]
        public bool|Optional $isActive,
        #[MapInputName(CamelCaseMapper::class)]
        public bool|Optional $isDraining,
        #[MapInputName(CamelCaseMapper::class)]
        public bool|Optional $isStorageServer,
        #[MapInputName(CamelCaseMapper::class)]
        public string|Optional|null $storageEndpoint,
        public string|Optional|null $accel,
        public string|Optional|null $env,
    ) {}

    public static function rules(): array
    {
        $node = request()->route('node');

        return [
            'name' => 'sometimes|string|max:255|unique:nodes,name,'.$node->id,
            // A POSIX login name; see StoreNodeData for why free text cannot be accepted here.
            'user' => ['nullable', 'string', 'max:32', 'regex:/^[a-z_][a-z0-9_-]*$/'],
            'ipAddress' => 'sometimes|ip',
            // A DNS name only; see StoreNodeData for where it ends up.
            'hostname' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/i'],
            'isActive' => 'sometimes|boolean',
            'isDraining' => 'sometimes|boolean',
            'isStorageServer' => [
                'sometimes', 'boolean',
                function ($attribute, $value, $fail) use ($node) {
                    if (filter_var($value, FILTER_VALIDATE_BOOLEAN)
                        && Node::where('is_storage_server', true)->where('id', '!=', $node->id)->exists()) {
                        $fail('Storage server exists.');
                    }
                },
            ],
            'storageEndpoint' => 'nullable|url',
            'accel' => 'nullable|string|in:intel,nvidia',
            'env' => 'nullable|string|max:10000',
        ];
    }
}
