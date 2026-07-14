<?php

namespace App\Http\Controllers;

use App\Data\ApiTokenData;
use App\Data\ProjectData;
use App\Services\ApiTokenService;
use Illuminate\Http\Request;

class ProjectApiKeyController extends Controller
{
    public function __construct(protected ApiTokenService $apiTokenService) {}

    public function __invoke(Request $request, string $ulid)
    {
        $project = $request->user()->projects()->where('ulid', $ulid)->firstOrFail();

        $token = $this->apiTokenService->regenerateProjectKey($project);

        // The plain-text key is only readable here; afterwards only its metadata is exposed.
        $data = ProjectData::fromModel($project->load('tokens'));
        $data->apiKey = ApiTokenData::fromNewAccessToken($token);

        return response()->json([
            'data' => $data,
            'message' => 'API key regenerated successfully',
        ]);
    }
}
