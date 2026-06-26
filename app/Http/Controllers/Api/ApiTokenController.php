<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApiToken\StoreApiTokenRequest;
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
            'data' => $tokens->map(fn ($token) => [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities,
                'lastUsedAt' => $token->last_used_at?->toISOString(),
                'createdAt' => $token->created_at->toISOString(),
                'expiresAt' => $token->expires_at?->toISOString(),
            ]),
        ]);
    }

    public function store(StoreApiTokenRequest $request): JsonResponse
    {
        $token = $this->apiTokenService->createToken($request->user(), $request->validated());

        return response()->json([
            'data' => [
                'id' => $token->accessToken->id,
                'name' => $token->accessToken->name,
                'token' => $token->plainTextToken,
                'abilities' => $token->accessToken->abilities,
                'createdAt' => $token->accessToken->created_at->toISOString(),
            ],
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
