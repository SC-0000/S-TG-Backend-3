<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminUsersSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $users = [
            [
                'name' => 'Super Admin',
                'email' => 'superadmin@mkzaso.com',
                'password' => 'super@dmin12',
                'role' => User::ROLE_SUPER_ADMIN,
            ],
            [
                'name' => 'Admin',
                'email' => 'admin@lnovic.com',
                'password' => '@dmin12',
                'role' => User::ROLE_ADMIN,
            ],
        ];

        foreach ($users as $data) {
            User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make($data['password']),
                    'role' => $data['role'],
                    'email_verified_at' => $now,
                ]
            );
        }
    }
}
