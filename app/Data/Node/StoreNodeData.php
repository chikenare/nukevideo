<?php

namespace App\Data\Node;

use App\Data\RequestData;
use App\Models\Node;
use App\Models\SshKey;
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
        public ?int $sshKeyId,
        #[MapInputName(CamelCaseMapper::class)]
        public ?string $storageEndpoint,
    ) {}

    /**
     * Snake-cased for the model, with the key filled in when the caller named none and there is
     * exactly one to pick. One key is the common installation; making the operator choose it on
     * every node is a form field with a single answer. With several keys the choice is real, and
     * a node created without one stays keyless — it cannot be deployed to until it is assigned.
     */
    public function toDatabase(): array
    {
        $data = parent::toDatabase();

        if (empty($data['ssh_key_id']) && SshKey::count() === 1) {
            $data['ssh_key_id'] = SshKey::value('id');
        }

        return $data;
    }

    public static function rules(): array
    {
        return [
            'hostname' => 'nullable|max:255',
            'name' => 'required|string|max:255|unique:nodes,name',
            // A POSIX login name. It is interpolated into the paths of the SSH deploy script, so
            // free text here is a shell injection on the node — and even an honest value carrying
            // a quote or a `$` breaks the deploy with an unreadable bash error.
            'user' => ['string', 'max:32', 'regex:/^[a-z_][a-z0-9_-]*$/'],
            'ipAddress' => 'required|ip',
            'type' => 'required|string|in:worker,proxy',
            'accel' => 'nullable|string|in:intel,nvidia',
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
