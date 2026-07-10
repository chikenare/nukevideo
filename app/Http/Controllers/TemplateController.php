<?php

namespace App\Http\Controllers;

use App\Data\Template\StoreTemplateData;
use App\Data\Template\UpdateTemplateData;
use App\Data\TemplateData;
use App\Data\TemplatePresetData;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TemplateController extends Controller
{
    public function index(Request $request)
    {
        $templates = $request->project()->templates()->latest()->get();

        return response()->json(['data' => $templates->map(fn ($t) => TemplateData::fromModel($t))->all()]);
    }

    public function show(Request $request, string $ulid)
    {
        $template = $request->user()->templates()->where('ulid', $ulid)->firstOrFail();

        return response()->json(['data' => TemplateData::fromModel($template)]);
    }

    public function store(Request $request, StoreTemplateData $data)
    {
        $template = $request->project()->templates()->create(
            $data->toDatabase() + ['user_id' => $request->user()->id]
        );

        return response()->json(['data' => TemplateData::fromModel($template)]);
    }

    public function update(Request $request, UpdateTemplateData $data, string $ulid)
    {
        $template = $request->user()->templates()->findOrFailByUlid($ulid);

        $template->update($data->toDatabase());

        return response()->json([
            'data' => TemplateData::fromModel($template->fresh()),
            'message' => 'Template updated successfully',
        ]);
    }

    public function destroy(Request $request, string $ulid)
    {
        $template = $request->user()->templates()->where('ulid', $ulid)->firstOrFail();

        $template->delete();

        return response()->json(['message' => 'Template deleted successfully']);
    }

    public function presets()
    {
        $presets = collect(config('template-presets'))->map(function ($preset, $slug) {
            return [
                'slug' => $slug,
                'name' => $preset['name'],
                'description' => $preset['description'],
                'category' => $preset['category'],
                'query' => $preset['query'],
            ];
        })->values();

        return response()->json(['data' => TemplatePresetData::collect($presets->all())]);
    }

    public function adoptPreset(Request $request, string $slug)
    {
        $presets = config('template-presets');

        if (! isset($presets[$slug])) {
            abort(404, 'Preset not found.');
        }

        $preset = $presets[$slug];

        $template = $request->project()->templates()->create([
            'name' => $preset['name'],
            'query' => $preset['query'],
            'user_id' => $request->user()->id,
        ]);

        return response()->json(['data' => TemplateData::fromModel($template)]);
    }

    public function getConfig()
    {
        return response()->json(['data' => [
            'codecs' => collect(config('ffmpeg.codecs'))->map($this->camelizeKeys(...))->all(),
            // Parameter names stay snake_case: they are the keys persisted in the template JSON.
            'parameters' => collect(config('ffmpeg.parameters'))->map($this->camelizeKeys(...))->all(),
        ]]);
    }

    private function camelizeKeys(array $attributes): array
    {
        return collect($attributes)->mapWithKeys(fn ($value, $key) => [Str::camel($key) => $value])->all();
    }
}
