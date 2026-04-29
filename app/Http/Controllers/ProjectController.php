<?php

namespace App\Http\Controllers;

use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $projects = $request->user()->projects()->latest()->get();

        return ProjectResource::collection($projects);
    }

    public function show(Request $request, string $ulid)
    {
        $project = $request->user()->projects()->where('ulid', $ulid)->firstOrFail();

        return new ProjectResource($project);
    }

    public function store(StoreProjectRequest $request)
    {
        $project = $request->user()->projects()->create($request->validated());

        return new ProjectResource($project);
    }

    public function update(UpdateProjectRequest $request, string $ulid)
    {
        $project = $request->user()->projects()->where('ulid', $ulid)->firstOrFail();

        $data = $request->validated();

        if (isset($data['settings'])) {
            $data['settings'] = array_merge($project->settings ?? [], $data['settings']);
        }

        $project->update($data);

        return response()->json([
            'data' => new ProjectResource($project->fresh()),
            'message' => 'Project updated successfully',
        ]);
    }

    public function destroy(Request $request, string $ulid)
    {
        $project = $request->user()->projects()->where('ulid', $ulid)->firstOrFail();

        if ($request->user()->projects()->count() <= 1) {
            return response()->json([
                'message' => 'Cannot delete your only project.',
            ], 409);
        }

        $project->videos->each->delete();
        $project->templates->each->delete();
        $project->tokens()->delete();

        $project->delete();

        return response()->json(['message' => 'Project deleted successfully']);
    }
}
