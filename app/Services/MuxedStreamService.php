<?php

namespace App\Services;

class MuxedStreamService extends BaseStreamService
{
    public function __construct(
        \App\Models\Stream $stream,
        private string $outputFormat,
    ) {
        parent::__construct($stream);
    }

    protected function getCompletionData(): array
    {
        $streamInfo = $this->getFFProbeInfo('v:0');

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

        $videoParams = collect($parametersConfig)
            ->filter(fn($config) => isset($config['type']) && $config['type'] === 'video')
            ->toArray();

        $audioParams = collect($parametersConfig)
            ->filter(fn($config) => isset($config['type']) && $config['type'] === 'audio')
            ->toArray();

        // Map first video stream
        $args[] = '-map 0:v:0';

        foreach ($params as $key => $value) {
            if (isset($videoParams[$key])) {
                $this->appendArgument($args, $videoParams[$key], $value);
            }
        }

        // Map all audio streams
        $args[] = '-map 0:a?';

        foreach ($params as $key => $value) {
            if (isset($audioParams[$key])) {
                $this->appendArgument($args, $audioParams[$key], $value);
            }
        }

        // Map all subtitle streams
        $args[] = '-map 0:s?';
        $args[] = $this->outputFormat === 'mp4' ? '-c:s mov_text' : '-c:s srt';

        // Faststart for mp4
        if ($this->outputFormat === 'mp4' && !in_array('-movflags +faststart', $args)) {
            $args[] = '-movflags +faststart';
        }

        return implode(' ', $args);
    }
}
