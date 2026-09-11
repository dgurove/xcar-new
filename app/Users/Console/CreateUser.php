<?php

namespace App\Users\Console;

use App\Support\Phone;
use App\Users\Role;
use App\Users\User;
use Illuminate\Console\Command;

class CreateUser extends Command
{
    protected $signature = 'user:create {--name=} {--phone=} {--email=} {--password=} {--role=admin} {--access=admin,park}';

    protected $description = 'Завести пользователя (по умолчанию администратора)';

    public function handle(): int
    {
        $phone = Phone::normalize($this->option('phone') ?: $this->ask('Телефон'));
        if (! $phone) {
            $this->error('Телефон не разобран');

            return self::FAILURE;
        }

        $user = User::updateOrCreate(['phone' => $phone], [
            'name' => $this->option('name') ?: $this->ask('Имя', 'Администратор'),
            'email' => $this->option('email') ?: null,
            'password' => $this->option('password') ?: $this->secret('Пароль'),
            'role' => Role::from($this->option('role')),
            'access' => array_values(array_filter(explode(',', $this->option('access')))),
        ]);

        $this->info("{$user->name}, {$user->phoneFormatted()}, {$user->role->label()}");

        return self::SUCCESS;
    }
}
