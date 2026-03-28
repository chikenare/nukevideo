<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdatePasswordRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Services\ProfileService;
use Illuminate\Http\JsonResponse;

class ProfileController extends Controller
{
    public function __construct(protected ProfileService $profileService) {}

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        $this->profileService->updateProfile($user, $request->validated());

        return response()->json([
            'data' => new UserResource($user->fresh()),
            'message' => 'Profile updated successfully.',
        ]);
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $this->profileService->updatePassword(
            $request->user(),
            $validated['current_password'],
            $validated['password']
        );

        return response()->json([
            'message' => 'Password updated successfully.',
        ]);
    }
}
