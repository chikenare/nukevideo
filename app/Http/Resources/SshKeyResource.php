<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SshKeyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'publicKey' => $this->public_key,
            'fingerprint' => $this->fingerprint,
            'createdAt' => $this->created_at,
        ];
    }
}
