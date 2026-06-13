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
     * Template param values come from user-editable template configs and end up
     * interpolated into a shell command line. Every legitimate ffmpeg value
     * (bitrates, presets, profiles, pix_fmts, sample rates) fits this charset;
     * anything else is rejected outright rather than handed to the shell.
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
        $x264Params = [];
        $codecKey = $type === 'video' ? 'video_codec' : 'audio_codec';
        $skip = $type === 'video' ? [$codecKey, 'width', 'height'] : [$codecKey];

        foreach ($params as $key => $value) {
            if (in_array($key, $skip)) {
                continue;
            }
            if (! isset($available[$key])) {
                continue;
            }

            $config = $available[$key];

            // Sub-options that share the -x264-params flag must be merged into a
            // single flag: ffmpeg only honours the last -x264-params it sees.
            if (isset($config['x264_param'])) {
                if ($value) {
                    $x264Params[] = $config['x264_param'];
                }

                continue;
            }

            $this->appendArgument($args, $config, $value);
        }

        if ($x264Params) {
            $args[] = '-x264-params '.implode(':', $x264Params);
        }

        return $args;
    }
}
