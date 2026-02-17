<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class TemplateFormatRule implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $codecs = collect(config('ffmpeg.codecs'))->keyBy('codec');
        $parameters = config('ffmpeg.parameters');

        $videoCodecKey = $value['video_codec'] ?? null;
        $audioCodecKey = $value['audio_codec'] ?? null;

        // Validate video codec exists and is a video type
        if (!$videoCodecKey || !$codecs->has($videoCodecKey)) {
            $fail("The video codec '{$videoCodecKey}' is invalid.");
            return;
        }

        $videoCodec = $codecs->get($videoCodecKey);
        if ($videoCodec['type'] !== 'video') {
            $fail("The codec '{$videoCodecKey}' is not a video codec.");
            return;
        }

        // Validate audio codec exists and is an audio type
        if (!$audioCodecKey || !$codecs->has($audioCodecKey)) {
            $fail("The audio codec '{$audioCodecKey}' is invalid.");
            return;
        }

        $audioCodec = $codecs->get($audioCodecKey);
        if ($audioCodec['type'] !== 'audio') {
            $fail("The codec '{$audioCodecKey}' is not an audio codec.");
            return;
        }

        // Check if audio codec is available for the selected video codec
        $audioCodecAvailableFor = $audioCodec['available_for'] ?? [];
        if (!in_array($videoCodecKey, $audioCodecAvailableFor)) {
            $fail("The audio codec '{$audioCodecKey}' is not available for video codec '{$videoCodecKey}'.");
            return;
        }

        // Get video parameters available for this video codec
        $videoParameters = collect($parameters)
            ->filter(function ($param) use ($videoCodecKey) {
                return $param['type'] === 'video'
                    && in_array($videoCodecKey, $param['available_for'] ?? []);
            })
            ->toArray();

        // Get audio parameters available for this audio codec
        $audioParameters = collect($parameters)
            ->filter(function ($param) use ($audioCodecKey) {
                return $param['type'] === 'audio'
                    && in_array($audioCodecKey, $param['available_for'] ?? []);
            })
            ->toArray();

        // Validate video parameters
        $this->validateParameters($value, $videoParameters, $fail);

        // Validate audio parameters
        $this->validateParameters($value, $audioParameters, $fail);
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
