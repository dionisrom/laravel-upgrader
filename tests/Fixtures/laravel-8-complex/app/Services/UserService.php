<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserService
{
    public function create(string $name, string $email, string $password, string $role = 'user'): User
    {
        $user = User::create([
            'name'     => $name,
            'email'    => $email,
            'password' => Hash::make($password),
        ]);

        $user->assignRole($role);

        return $user;
    }

    public function updatePassword(User $user, string $newPassword): void
    {
        $user->update(['password' => Hash::make($newPassword)]);
    }

    public function deactivate(User $user): void
    {
        $user->update(['active' => false]);
        $user->tokens()->delete();
    }
}
