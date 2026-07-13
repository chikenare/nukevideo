<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Model events stay enabled: UserObserver is what gives the user its default project.
     */
    public function run(): void
    {
        User::unguarded(fn () => User::factory()->create([
            'ulid' => Str::ulid(),
            'name' => 'Test User',
            'email' => 'test@example.com',
            'is_admin' => true,
        ]));
    }
}
