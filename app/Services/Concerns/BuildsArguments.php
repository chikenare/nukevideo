<?php

namespace App\Services\Concerns;

trait BuildsArguments
{
    private function appendArgument(array &$args, array $config, mixed $value): void
    {
        if (! isset($config['template'])) {
            return;
        }

        $inputType = $config['input_type'] ?? $config['type'] ?? null;

        if ($inputType === 'boolean') {
            if ($value) {
                $args[] = str_contains($config['template'], '%s')
                    ? sprintf($config['template'], 1)
                    : $config['template'];
            }

            return;
        }

        if ($value === null || $value === '') {
            return;
        }

        $args[] = sprintf($config['template'], $value);
    }

    private function buildParamsArguments(array $params, string $type): array
    {
        $parametersConfig = config('ffmpeg.parameters', []);
        $available = collect($parametersConfig)
            ->filter(fn ($config) => isset($config['type']) && $config['type'] === $type)
            ->toArray();

        $args = [];
        $codecKey = $type === 'video' ? 'video_codec' : 'audio_codec';
        $skip = $type === 'video' ? [$codecKey, 'width', 'height'] : [$codecKey];

        foreach ($params as $key => $value) {
            if (in_array($key, $skip)) {
                continue;
            }
            if (isset($available[$key])) {
                $this->appendArgument($args, $available[$key], $value);
            }
        }

        return $args;
    }
}
