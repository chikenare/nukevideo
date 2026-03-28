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
        $user = User::create($data);

        if (isset($data['is_admin'])) {
            $user->is_admin = $data['is_admin'];
            $user->save();
        }

        return $user;
    }

    public function update(User $user, array $data): User
    {
        if (empty($data['password'])) {
            unset($data['password']);
        }

        $user->update($data);

        if (array_key_exists('is_admin', $data)) {
            $user->is_admin = $data['is_admin'];
            $user->save();
        }

        return $user->fresh();
    }

    public function delete(User $user): void
    {
        $user->delete();
    }
}
