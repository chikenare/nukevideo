<?php

namespace App\Http\Controllers\Api;

use App\Data\User\StoreUserData;
use App\Data\User\UpdateUserData;
use App\Data\UserData;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\UserService;

class UserController extends Controller
{
    public function __construct(
        private UserService $userService,
    ) {}

    public function index()
    {
        return $this->userService->index();
    }

    public function store(StoreUserData $data)
    {
        $user = $this->userService->create($data->toDatabase());

        return response()->json(['data' => UserData::fromModel($user)]);
    }

    public function show(string $id)
    {
        $user = User::findOrFail($id);

        return response()->json(['data' => UserData::fromModel($user)]);
    }

    public function update(UpdateUserData $data, string $id)
    {
        $user = User::findOrFail($id);

        $user = $this->userService->update($user, $data->toDatabase());

        return response()->json([
            'data' => UserData::fromModel($user->fresh()),
            'message' => 'User updated successfully',
        ]);
    }

    public function destroy(string $id)
    {
        $user = User::findOrFail($id);

        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'You cannot eliminate yourself.'], 403);
        }

        $this->userService->delete($user);

        return response()->json(['message' => 'User deleted successfully']);
    }
}
