<?php

namespace App\Services\Concerns;

use InvalidArgumentException;

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

        $args[] = sprintf($config['template'], $this->assertSafeArgValue($value));
    }

    /**
     * Template values are user-editable and interpolated into a shell command, so reject
     * anything outside the charset every legitimate ffmpeg value fits.
     */
    private function assertSafeArgValue(mixed $value): string
    {
        $value = (string) $value;

        if (! preg_match('/^[A-Za-z0-9._+:-]+$/', $value)) {
            throw new InvalidArgumentException("Unsafe ffmpeg argument value: {$value}");
        }

        return $value;
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
            if (! isset($available[$key])) {
                continue;
            }

            $this->appendArgument($args, $available[$key], $value);
        }

        return $args;
    }
}
