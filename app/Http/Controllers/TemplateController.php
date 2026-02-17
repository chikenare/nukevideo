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
        return response()->json(['message' => 'Template updated successfully']);
    }

    public function destroy(Request $request, string $ulid)
    {
        $template = $request->user()->templates()->where('ulid', $ulid)->firstOrFail();

        $template->delete();

        return response()->json(['message' => 'Template deleted successfully']);

    }

    public function getConfig()
    {
        $config = config('ffmpeg');
        return response()->json(['data' => $config]);
    }

}
