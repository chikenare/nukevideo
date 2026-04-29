<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OutputResource extends JsonResource
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
            'format' => $this->format,
            'streams' => StreamResource::collection($this->whenLoaded('streams')),
            'createdAt' => $this->created_at,
        ];
    }
}
