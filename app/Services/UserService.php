<?php

namespace App\Services;

use App\Data\UserData;
use App\Models\User;

class UserService
{
    public function __construct(
        private ProjectService $projects,
    ) {}

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

    /**
     * `users -> projects -> videos` cascades in the schema, so a bare delete would drop every
     * video row without ever running VideoObserver — leaving each package, source and thumbnail
     * on S3 forever, since nothing sweeps primary storage. Go down through the projects instead.
     */
    public function delete(User $user): void
    {
        $user->projects()->cursor()->each(fn ($project) => $this->projects->delete($project));

        $user->delete();
    }
}
