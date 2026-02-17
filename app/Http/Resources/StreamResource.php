<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StreamResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'parentId' => $this->parent_id,
            'name' => $this->name,
            'type' => $this->type,
            'size' => $this->size,
            'inputParams' => $this->input_params,
            'meta' => $this->meta,
            'status' => $this->status,
            'progress' => $this->progress,

            'width' => $this->width,
            'height' => $this->height,
            'language' => $this->language,
            'channels' => $this->channels,

            'startedAt' => $this->started_at,
            'completedAt' => $this->completed_at,

            'errorLog' => $this->error_log,

            'createdAt' => $this->created_At

        ];
    }
}
