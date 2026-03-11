<?php

namespace App\Services;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Support\Str;

class UserService
{
    public function index()
    {
        $users = User::latest()->get();

        return UserResource::collection($users);
    }

    public function create(array $data): User
    {
        $data['uuid'] = (string) Str::uuid();

        return User::create($data);
    }

    public function update(User $user, array $data): User
    {
        if (empty($data['password'])) {
            unset($data['password']);
        }

        $user->update($data);

        return $user->fresh();
    }

    public function delete(User $user): void
    {
        $user->delete();
    }
}
