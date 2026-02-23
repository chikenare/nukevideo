<?php

namespace App\Services;

class StreamService extends BaseStreamService
{
    public function handle(): void
    {
        parent::handle();
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

}
