<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VideoResource extends JsonResource
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
            'name' => $this->name,
            'duration' => $this->duration,
            'aspectRatio' => $this->aspect_ratio,
            'status' => $this->status,
            'createdAt' => $this->created_at,

            'thumbnailUrl' => $this->thumbnail_path ? url("/videos/{$this->thumbnail_path}") : null,
            'storyboardUrl' => url("/videos/{$this->ulid}/storyboard.vtt"),

            'outputFormat' => $this->output_format,

            'streams' => StreamResource::collection($this->whenLoaded('streams')),

            'size' => $this->whenLoaded('streams', function () {
                return $this->streams->sum('size');
            }),
        ];
    }
}
