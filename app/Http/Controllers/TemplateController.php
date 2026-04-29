<?php

namespace App\Http\Controllers;

use App\Http\Requests\Template\StoreTemplateRequest;
use App\Http\Requests\Template\UpdateTemplateRequest;
use App\Http\Resources\TemplateResource;
use App\Models\Node;
use App\Services\CodecService;
use Illuminate\Http\Request;

class TemplateController extends Controller
{
    public function index(Request $request)
    {
        $templates = $request->project()->templates()->latest()->get();

        return TemplateResource::collection($templates);
    }

    public function show(Request $request, string $ulid)
    {
        $template = $request->user()->templates()->where('ulid', $ulid)->firstOrFail();

        return new TemplateResource($template);
    }

    public function store(StoreTemplateRequest $request)
    {
        $template = $request->project()->templates()->create(
            $request->validated() + ['user_id' => $request->user()->id]
        );

        return new TemplateResource($template);
    }

    public function update(UpdateTemplateRequest $request, string $ulid)
    {
        $template = $request->user()->templates()->findOrFailByUlid($ulid);

        $template->update($request->validated());

        return response()->json([
            'data' => new TemplateResource($template->fresh()),
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

        return response()->json(['data' => $presets]);
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

        return new TemplateResource($template);
    }

    public function getConfig()
    {
        $config = config('ffmpeg');

        $hasGpuNode = Node::where('is_active', true)
            ->where('type', 'worker')
            ->where('has_gpu', true)
            ->exists();

        if (! $hasGpuNode) {
            $gpuCodecNames = CodecService::gpuCodecs();

            $config['codecs'] = array_values(
                collect($config['codecs'])
                    ->reject(fn ($c) => ! empty($c['requires_gpu']))
                    ->all()
            );

            $config['parameters'] = collect($config['parameters'])
                ->map(function ($param) use ($gpuCodecNames) {
                    if (isset($param['available_for'])) {
                        $param['available_for'] = array_values(
                            array_diff($param['available_for'], $gpuCodecNames)
                        );
                    }

                    return $param;
                })
                ->filter(fn ($param) => ! isset($param['available_for']) || ! empty($param['available_for']))
                ->all();
        }

        return response()->json(['data' => $config]);
    }
}
