<?php

namespace App\Http\Requests\Node;

use Illuminate\Foundation\Http\FormRequest;

class StoreNodeRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'hostname' => 'nullable|max:255',
            'name' => 'required|string|max:255|unique:nodes,name',
            'user' => 'string|max:32',
            'ip_address' => 'required|ip',
            'type' => 'required|string|in:worker,proxy',
            'ssh_key_id' => 'nullable|exists:ssh_keys,id',
            'has_gpu' => 'sometimes|boolean',
            'cdn_mode' => 'sometimes|boolean',
            'workers' => $this->input('type') === 'proxy'
                ? 'nullable|integer|min:1|max:1'
                : 'nullable|integer|min:1|max:20',
        ];
    }
}
