<?php

namespace App\Http\Resources\Webhook;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VideoWebhookResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'project_ulid' => $this->project?->ulid,
            'name' => $this->name,
            'status' => $this->status,
            'duration' => $this->duration,
            'aspect_ratio' => $this->aspect_ratio,
            'external_user_id' => $this->external_user_id,
            'external_resource_id' => $this->external_resource_id,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
