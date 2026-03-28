<?php

namespace App\Http\Controllers;

use App\Http\Requests\Template\StoreTemplateRequest;
use App\Http\Requests\Template\UpdateTemplateRequest;
use App\Http\Resources\TemplateResource;
use Illuminate\Http\Request;

class TemplateController extends Controller
{
    public function index(Request $request)
    {
        $templates = $request->user()->templates()->latest()->get();

        return TemplateResource::collection($templates);
    }

    public function show(Request $request, string $ulid)
    {
        $template = $request->user()->templates()->where('ulid', $ulid)->firstOrFail();

        return new TemplateResource($template);
    }

    public function store(StoreTemplateRequest $request)
    {
        $template = $request->user()->templates()->create($request->validated());

        return new TemplateResource($template);
    }

    public function update(UpdateTemplateRequest $request, string $ulid)
    {
        $template = $request->user()->templates()->findOrFailByUlid($ulid);

        $template->update($request->validated());

        return response()->json([
            'data' => new TemplateResource($template->fresh()),
            'message' => 'Template updated successfully'
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

        return response()->json(['data' => $presets]);
    }

    public function adoptPreset(Request $request, string $slug)
    {
        $presets = config('template-presets');

        if (! isset($presets[$slug])) {
            abort(404, 'Preset not found.');
        }

        $preset = $presets[$slug];

        $template = $request->user()->templates()->create([
            'name' => $preset['name'],
            'query' => $preset['query'],
        ]);

        return new TemplateResource($template);
    }

    public function getConfig()
    {
        $config = config('ffmpeg');

        return response()->json(['data' => $config]);
    }
}
