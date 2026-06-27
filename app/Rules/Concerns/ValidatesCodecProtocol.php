<?php

namespace App\Rules\Concerns;

use Closure;

/**
 * Asserts a codec is compatible with its output format's protocols (e.g. nginx-vod only
 * packages Opus for DASH). The format is a sibling field of the variant/audio being validated,
 * resolved from the full request data via the attribute path.
 */
trait ValidatesCodecProtocol
{
    /** Full data under validation, injected via DataAwareRule::setData(). */
    protected array $data = [];

    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /** Attributes look like "query.outputs.0.variants.1" or "query.outputs.0.audio". */
    protected function outputFormat(string $attribute): ?string
    {
        if (! preg_match('/outputs\.(\d+)\b/', $attribute, $matches)) {
            return null;
        }

        return data_get($this->data, "query.outputs.{$matches[1]}.format");
    }

    /** The video codec is set on the output; variants inherit it. */
    protected function outputVideoCodec(string $attribute): ?string
    {
        if (! preg_match('/outputs\.(\d+)\b/', $attribute, $matches)) {
            return null;
        }

        return data_get($this->data, "query.outputs.{$matches[1]}.video_codec");
    }

    /** Formats without a protocol restriction accept any codec. */
    protected function assertCodecProtocol(string $attribute, array $codec, Closure $fail): bool
    {
        $format = $this->outputFormat($attribute);

        if (! $format) {
            return true;
        }

        $formatProtocols = config("ffmpeg.formats.{$format}.protocols", []);

        if (empty($formatProtocols)) {
            return true;
        }

        $codecProtocols = $codec['protocols'] ?? [];

        if (empty(array_intersect($codecProtocols, $formatProtocols))) {
            $fail("The codec '{$codec['codec']}' is not supported by the '{$format}' format.");

            return false;
        }

        return true;
    }
}
