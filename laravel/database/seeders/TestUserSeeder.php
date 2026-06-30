<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class TestUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'test@test.test'],
            [
                'name' => 'Test User',
                'password' => '111333',
                'email_verified_at' => now(),
            ],
        );
    }
}
