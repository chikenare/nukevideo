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
        public ?string $accel,
        public string|Optional $user,
        #[MapInputName(CamelCaseMapper::class)]
        public bool|Optional $isStorageServer,
        public ?string $hostname,
        #[MapInputName(CamelCaseMapper::class)]
        public ?string $storageEndpoint,
    ) {}

    public static function rules(): array
    {
        return [
            // A DNS name and nothing else. It lands inside the edge's Traefik ``Host(`…`)`` rule,
            // in the playback URLs handed to integrators and in the URL the health probe fetches:
            // a backtick or a slash here rewrites a router rule or points the probe elsewhere.
            'hostname' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/i'],
            'name' => 'required|string|max:255|unique:nodes,name',
            // A POSIX login name. It is interpolated into the paths of the SSH deploy script, so
            // free text here is a shell injection on the node — and even an honest value carrying
            // a quote or a `$` breaks the deploy with an unreadable bash error.
            'user' => ['string', 'max:32', 'regex:/^[a-z_][a-z0-9_-]*$/'],
            'ipAddress' => 'required|ip',
            'type' => 'required|string|in:worker,proxy',
            'accel' => 'nullable|string|in:intel,nvidia',
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
