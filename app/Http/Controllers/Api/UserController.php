<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Http\Resources\UserResource;
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

    public function store(StoreUserRequest $request)
    {
        $user = $this->userService->create($request->validated());

        return new UserResource($user);
    }

    public function show(string $id)
    {
        $user = User::findOrFail($id);

        return new UserResource($user);
    }

    public function update(UpdateUserRequest $request, string $id)
    {
        $user = User::findOrFail($id);

        $user = $this->userService->update($user, $request->validated());

        return response()->json([
            'data' => new UserResource($user->fresh()),
            'message' => 'User updated successfully'
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
