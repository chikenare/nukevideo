<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class TemplateAudioRule implements ValidationRule
{
    /**
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $codecs = collect(config('ffmpeg.codecs'))->keyBy('codec');
        $parameters = config('ffmpeg.parameters');

        $audioCodecKey = $value['audio_codec'] ?? null;

        if (!$audioCodecKey || !$codecs->has($audioCodecKey)) {
            $fail("The audio codec '{$audioCodecKey}' is invalid.");
            return;
        }

        $audioCodec = $codecs->get($audioCodecKey);
        if ($audioCodec['type'] !== 'audio') {
            $fail("The codec '{$audioCodecKey}' is not an audio codec.");
            return;
        }

        // Validate shared audio parameters (excluding channels and audio_bitrate)
        $audioParameters = collect($parameters)
            ->filter(function ($param, $key) use ($audioCodecKey) {
                return $param['type'] === 'audio'
                    && in_array($audioCodecKey, $param['available_for'] ?? [])
                    && !in_array($key, ['channels', 'audio_bitrate']);
            })
            ->toArray();

        $this->validateParameters($value, $audioParameters, $fail);

        // Validate channels array
        $channels = $value['channels'] ?? [];
        if (!is_array($channels) || empty($channels)) {
            $fail('At least one audio channel configuration is required.');
            return;
        }

        $channelRules = collect($parameters)
            ->filter(function ($param, $key) use ($audioCodecKey) {
                return $param['type'] === 'audio'
                    && in_array($audioCodecKey, $param['available_for'] ?? [])
                    && in_array($key, ['channels', 'audio_bitrate']);
            })
            ->toArray();

        foreach ($channels as $index => $channelConfig) {
            if (!is_array($channelConfig)) {
                $fail("Channel configuration at index {$index} is invalid.");
                continue;
            }

            $this->validateParameters($channelConfig, $channelRules, $fail);
        }
    }

    protected function validateParameters(array $data, array $parameters, Closure $fail): void
    {
        $rules = [];
        $attributes = [];

        foreach ($parameters as $paramKey => $config) {
            if (isset($config['rules'])) {
                $rules[$paramKey] = $config['rules'];
                $attributes[$paramKey] = $config['label'] ?? $paramKey;
            }
        }

        $validator = \Illuminate\Support\Facades\Validator::make($data, $rules, [], $attributes);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $fail($message);
            }
        }
    }
}
