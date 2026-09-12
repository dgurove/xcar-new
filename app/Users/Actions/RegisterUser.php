<?php

namespace App\Users\Actions;

use App\Users\Events\UserRegistered;
use App\Users\Role;
use App\Users\User;

final class RegisterUser
{
    /** @param array{name: string, phone: string, email?: ?string, password: string} $data */
    public function __invoke(array $data): User
    {
        $user = User::create([
            'name' => trim($data['name']),
            'phone' => $data['phone'],
            'email' => $data['email'] ?: null,
            'password' => $data['password'],
            'role' => Role::Visitor,
        ]);
        UserRegistered::dispatch($user);

        return $user;
    }
}
