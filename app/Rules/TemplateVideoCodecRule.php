<?php

namespace App\Rules;

use App\Rules\Concerns\ValidatesCodecProtocol;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates the output-level video codec: it must be a known video codec and compatible with the
 * output's format protocols. Mirrors {@see TemplateAudioRule} for audio. The per-variant parameters
 * are validated separately by {@see TemplateFormatRule}.
 */
class TemplateVideoCodecRule implements DataAwareRule, ValidationRule
{
    use ValidatesCodecProtocol;

    /**
     * @param  \Closure(string, ?string=): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $codecs = collect(config('ffmpeg.codecs'))->keyBy('codec');

        if (! $value || ! $codecs->has($value)) {
            $fail("The video codec '{$value}' is invalid.");

            return;
        }

        $codec = $codecs->get($value);

        if ($codec['type'] !== 'video') {
            $fail("The codec '{$value}' is not a video codec.");

            return;
        }

        $this->assertCodecProtocol($attribute, $codec, $fail);
    }
}
