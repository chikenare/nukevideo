<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::unguarded(fn () => User::factory()->create([
            'ulid' => Str::ulid(),
            'name' => 'Test User',
            'email' => 'test@example.com',
            'is_admin' => true,
        ]));
    }
}
