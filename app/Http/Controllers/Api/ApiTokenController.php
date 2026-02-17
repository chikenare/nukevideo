<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApiTokenController extends Controller
{
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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $token = $request->user()->createToken($validated['name']);

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
        $token = $request->user()->tokens()->findOrFail($id);
        $token->delete();

        return response()->json([
            'message' => 'API token revoked successfully.',
        ]);
    }
}
