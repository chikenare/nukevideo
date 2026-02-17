<?php

namespace App\Services;

use App\Enums\VideoStatus;
use App\Models\Stream;

class StreamService extends BaseStreamService
{
    public function handle(): void
    {
        parent::handle();

        $this->updateSharedPathStreams();
    }

    protected function getCompletionData(): array
    {
        if ($this->stream->type !== 'video') {
            return [];
        }

        $streamInfo = $this->getFFProbeInfo();

        if (!$streamInfo) {
            return [];
        }

        return [
            'width' => $streamInfo['width'],
            'height' => $streamInfo['height'],
        ];
    }

    protected function buildArguments(): string
    {
        $params = $this->stream->input_params ?? [];
        $args = [];
        $parametersConfig = config('ffmpeg.parameters', []);

        $availableParams = collect($parametersConfig)
            ->filter(fn($config) => isset($config['type']) && $config['type'] === $this->stream->type)
            ->toArray();

        foreach ($params as $key => $value) {
            if (isset($availableParams[$key])) {
                $this->appendArgument($args, $availableParams[$key], $value);
            }
        }

        $this->appendStreamMapping($args);

        return implode(' ', $args);
    }

    private function appendStreamMapping(array &$args): void
    {
        $streamIndex = $this->stream->meta['index'];

        $args[] = "-map 0:$streamIndex";

        match ($this->stream->type) {
            'video' => $args[] = '-an -sn',
            'audio' => $args[] = '-vn -sn',
            'subtitle', 'subtitles' => $args[] = '-vn -an',
            default => null,
        };
    }

    private function updateSharedPathStreams(): void
    {
        Stream::where('path', $this->stream->path)
            ->where('video_id', $this->stream->video_id)
            ->where('type', $this->stream->type)
            ->where('status', VideoStatus::PENDING->value)
            ->update([
                'size' => filesize($this->streamLocalPath),
                'status' => VideoStatus::COMPLETED->value,
                'progress' => 100,
                'completed_at' => now(),
            ]);
    }
}
