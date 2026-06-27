<?php

namespace App\Rules;

use App\Rules\Concerns\ValidatesCodecProtocol;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

class TemplateFormatRule implements DataAwareRule, ValidationRule
{
    use ValidatesCodecProtocol;

    /**
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $codecs = collect(config('ffmpeg.codecs'))->keyBy('codec');
        $parameters = config('ffmpeg.parameters');

        // The codec is set on the output; an invalid/missing one is reported by TemplateVideoCodecRule.
        $videoCodecKey = $this->outputVideoCodec($attribute);

        if (! $videoCodecKey || ! $codecs->has($videoCodecKey)) {
            return;
        }

        $videoParameters = collect($parameters)
            ->filter(function ($param) use ($videoCodecKey) {
                return $param['type'] === 'video'
                    && in_array($videoCodecKey, $param['available_for'] ?? []);
            })
            ->toArray();

        $this->validateParameters($value, $videoParameters, $fail);
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
