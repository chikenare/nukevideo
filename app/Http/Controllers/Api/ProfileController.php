<?php

namespace App\Http\Controllers\Api;

use App\Data\Profile\UpdatePasswordData;
use App\Data\Profile\UpdateProfileData;
use App\Data\UserData;
use App\Http\Controllers\Controller;
use App\Services\ProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(protected ProfileService $profileService) {}

    public function update(Request $request, UpdateProfileData $data): JsonResponse
    {
        $user = $request->user();

        $this->profileService->updateProfile($user, $data->toDatabase());

        return response()->json([
            'data' => UserData::fromModel($user->fresh()),
            'message' => 'Profile updated successfully.',
        ]);
    }

    public function updatePassword(Request $request, UpdatePasswordData $data): JsonResponse
    {
        $this->profileService->updatePassword(
            $request->user(),
            $data->currentPassword,
            $data->password
        );

        return response()->json([
            'message' => 'Password updated successfully.',
        ]);
    }
}
