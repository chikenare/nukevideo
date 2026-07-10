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
        public int|Optional|null $sshKeyId,
        #[MapInputName(CamelCaseMapper::class)]
        public bool|Optional $isStorageServer,
        #[MapInputName(CamelCaseMapper::class)]
        public string|Optional|null $storageEndpoint,
        public string|Optional|null $env,
    ) {}

    public static function rules(): array
    {
        $node = request()->route('node');

        return [
            'name' => 'sometimes|string|max:255|unique:nodes,name,'.$node->id,
            'user' => 'nullable|string|max:32',
            'ipAddress' => 'sometimes|ip',
            'hostname' => 'nullable|max:255',
            'isActive' => 'sometimes|boolean',
            'sshKeyId' => 'nullable|exists:ssh_keys,id',
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
            'env' => 'nullable|string|max:10000',
        ];
    }
}
