<?php

namespace App\Http\Requests\Node;

use App\Models\Node;
use Illuminate\Foundation\Http\FormRequest;

class UpdateNodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $node = $this->route('node');

        return [
            'name' => 'sometimes|string|max:255|unique:nodes,name,'.$node->id,
            'user' => 'nullable|string|max:32',
            'ip_address' => 'sometimes|ip',
            'hostname' => 'nullable|max:255',
            'is_active' => 'sometimes|boolean',
            'ssh_key_id' => 'nullable|exists:ssh_keys,id',
            'cdn_mode' => 'sometimes|boolean',
            'is_storage_server' => [
                'sometimes', 'boolean',
                function ($attribute, $value, $fail) use ($node) {
                    if (filter_var($value, FILTER_VALIDATE_BOOLEAN)
                        && Node::where('is_storage_server', true)->where('id', '!=', $node->id)->exists()) {
                        $fail('Storage server exists.');
                    }
                },
            ],
            'storage_endpoint' => 'nullable|url',
            'env' => 'nullable|string|max:10000',
        ];
    }
}
