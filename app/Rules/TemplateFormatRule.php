<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

class TemplateFormatRule implements DataAwareRule, ValidationRule
{
    /** Full data under validation, injected via DataAwareRule::setData(). */
    protected array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

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

    /** The video codec is set on the output; variants inherit it. Attributes look like
     *  "query.outputs.0.variants.1". */
    protected function outputVideoCodec(string $attribute): ?string
    {
        if (! preg_match('/outputs\.(\d+)\b/', $attribute, $matches)) {
            return null;
        }

        return data_get($this->data, "query.outputs.{$matches[1]}.video_codec");
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
