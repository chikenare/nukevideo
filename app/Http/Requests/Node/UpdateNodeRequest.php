<?php

namespace App\Http\Requests\Node;

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
        $nodeType = $this->input('type', $node->type->value);

        return [
            'name' => 'sometimes|string|max:255|unique:nodes,name,'.$node->id,
            'user' => 'nullable|string|max:32',
            'ip_address' => 'sometimes|ip',
            'hostname' => 'nullable|max:255',
            'is_active' => 'sometimes|boolean',
            'ssh_key_id' => 'nullable|exists:ssh_keys,id',
            'has_gpu' => 'sometimes|boolean',
            'cdn_mode' => 'sometimes|boolean',
            'workers' => $nodeType === 'proxy'
                ? 'nullable|integer|min:1|max:1'
                : 'nullable|integer|min:1|max:20',
            'env' => 'nullable|string|max:10000',
        ];
    }
}
