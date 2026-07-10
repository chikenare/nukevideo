<?php

namespace App\Data\Template;

use App\Data\RequestData;
use App\Rules\TemplateAudioRule;
use App\Rules\TemplateFormatRule;
use App\Rules\TemplateVideoCodecRule;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Optional;

class UpdateTemplateData extends RequestData
{
    public function __construct(
        public string|Optional $name,
        #[MapInputName(CamelCaseMapper::class)]
        public bool|Optional $keepProcessedFiles,
        #[MapInputName(CamelCaseMapper::class)]
        public bool|Optional $keepOriginal,
        /** @var array<string, mixed> */
        public array|Optional $query,
    ) {}

    public static function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'keepProcessedFiles' => 'sometimes|boolean',
            'keepOriginal' => 'sometimes|boolean',
            'query.outputs' => 'sometimes|array|min:1',
            'query.outputs.*.video_codec' => ['required', 'string', new TemplateVideoCodecRule],
            'query.outputs.*.variants' => 'required|array|min:1',
            'query.outputs.*.variants.*' => new TemplateFormatRule,
            'query.outputs.*.audio' => ['required', 'array', new TemplateAudioRule],
        ];
    }
}
