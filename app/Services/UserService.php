<?php

namespace App\Services;

use App\Data\UserData;
use App\Models\User;

class UserService
{
    public function index()
    {
        $users = User::latest()->get();

        return ['data' => $users->map(fn ($u) => UserData::fromModel($u))->all()];
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
