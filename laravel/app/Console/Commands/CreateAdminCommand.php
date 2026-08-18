<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CreateAdminCommand extends Command
{
    protected $signature = 'admin:create';

    protected $description = 'Create or update the admin user from environment credentials';

    public function handle(): int
    {
        $email = config('services.admin.email');
        $password = config('services.admin.password');
        $name = config('services.admin.name', 'Admin');

        if (empty($email) || empty($password)) {
            $this->error('ADMIN_EMAIL and ADMIN_PASSWORD must be set in your .env file.');

            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $password,
                'email_verified_at' => now(),
                'role' => User::ROLE_ADMIN,
                'is_approved' => true,
            ],
        );

        $this->info("Admin user created/updated: {$user->email} (role: {$user->role})");

        return self::SUCCESS;
    }
}
