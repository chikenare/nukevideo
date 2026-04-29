<?php

namespace App\Http\Resources;

use App\Services\Concerns\BuildsArguments;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TemplateResource extends JsonResource
{
    use BuildsArguments;

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
            'query' => $this->query,
            'commands' => $this->buildCommands(),
            'createdAt' => $this->created_at,
            'updatedAt' => $this->updated_at,
        ];
    }

    private function buildCommands(): array
    {
        $commands = [];

        foreach ($this->query['outputs'] ?? [] as $output) {
            foreach ($output['variants'] ?? [] as $variant) {
                $args = [];

                $codec = $variant['video_codec'] ?? null;
                if ($codec) {
                    $args[] = "-c:v {$codec}";
                }

                $width = $variant['width'] ?? 0;
                $height = $variant['height'] ?? 0;
                if ($width > 0 && $height > 0) {
                    $args[] = "-vf scale={$width}:{$height}";
                }

                $args = array_merge($args, $this->buildParamsArguments($variant, 'video'));

                $audio = $output['audio'] ?? [];
                $audioCodec = $audio['audio_codec'] ?? null;
                if ($audioCodec) {
                    $args[] = "-c:a {$audioCodec}";
                }

                $channel = $audio['channels'][0] ?? [];
                $args = array_merge($args, $this->buildParamsArguments($channel, 'audio'));

                $argString = implode(' ', $args);
                $commands[] = "ffmpeg -hide_banner -y -i \"input\" {$argString} \"output\"";
            }
        }

        return $commands;
    }
}
