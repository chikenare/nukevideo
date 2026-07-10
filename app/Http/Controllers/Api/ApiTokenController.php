<?php

namespace App\Http\Controllers\Api;

use App\Data\ApiToken\StoreApiTokenData;
use App\Data\ApiTokenData;
use App\Http\Controllers\Controller;
use App\Services\ApiTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiTokenController extends Controller
{
    public function __construct(protected ApiTokenService $apiTokenService) {}

    public function index(Request $request): JsonResponse
    {
        $tokens = $request->user()->tokens()->orderByDesc('created_at')->get();

        return response()->json([
            'data' => $tokens->map(fn ($token) => ApiTokenData::fromModel($token)),
        ]);
    }

    public function store(Request $request, StoreApiTokenData $data): JsonResponse
    {
        $token = $this->apiTokenService->createToken($request->user(), $data->toDatabase());

        return response()->json([
            'data' => ApiTokenData::fromNewAccessToken($token),
            'message' => 'API token created successfully.',
        ], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->apiTokenService->revokeToken($request->user(), $id);

        return response()->json([
            'message' => 'API token revoked successfully.',
        ]);
    }
}
