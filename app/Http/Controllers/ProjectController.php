<?php

namespace App\Http\Controllers;

use App\Data\Project\StoreProjectData;
use App\Data\Project\UpdateProjectData;
use App\Data\ProjectData;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $projects = $request->user()->projects()->latest()->get();

        return response()->json(['data' => $projects->map(fn ($p) => ProjectData::fromModel($p))->all()]);
    }

    public function show(Request $request, string $ulid)
    {
        $project = $request->user()->projects()->where('ulid', $ulid)->firstOrFail();

        return response()->json(['data' => ProjectData::fromModel($project)]);
    }

    public function store(Request $request, StoreProjectData $data)
    {
        $project = $request->user()->projects()->create($data->toDatabase());

        return response()->json(['data' => ProjectData::fromModel($project)]);
    }

    public function update(Request $request, UpdateProjectData $data, string $ulid)
    {
        $project = $request->user()->projects()->where('ulid', $ulid)->firstOrFail();

        $payload = $data->toDatabase();

        if (isset($payload['settings'])) {
            $payload['settings'] = array_merge($project->settings ?? [], $payload['settings']);
        }

        $project->update($payload);

        return response()->json([
            'data' => ProjectData::fromModel($project->fresh()),
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
