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
            'instances' => 'nullable|array',
            'instances.*.nano_cpus' => 'required|integer|min:1',
            'instances.*.memory_bytes' => 'required|integer|min:1',
            'instances.*.workload' => $this->input('type') === 'worker'
            ? 'required|string|in:light,medium,heavy'
            : 'nullable',
        ];
    }
}