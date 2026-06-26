<?php

namespace App\Http\Requests\Node;

use App\Models\Node;
use Illuminate\Foundation\Http\FormRequest;

class StoreNodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'hostname' => 'nullable|max:255',
            'name' => 'required|string|max:255|unique:nodes,name',
            'user' => 'string|max:32',
            'ip_address' => 'required|ip',
            'type' => 'required|string|in:worker,proxy',
            'ssh_key_id' => 'nullable|exists:ssh_keys,id',
            'cdn_mode' => 'sometimes|boolean',
            'is_storage_server' => [
                'sometimes', 'boolean',
                function ($attribute, $value, $fail) {
                    if (filter_var($value, FILTER_VALIDATE_BOOLEAN) && Node::where('is_storage_server', true)->exists()) {
                        $fail('Storage server exists.');
                    }
                },
            ],
            // Full endpoint (e.g. http://10.0.0.5:9000) where this node's RustFS store is reachable.
            'storage_endpoint' => 'nullable|url',
        ];
    }
}
