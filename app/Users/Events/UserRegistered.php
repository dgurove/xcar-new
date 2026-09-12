<?php

namespace App\Users\Events;

use App\Users\User;
use Illuminate\Foundation\Events\Dispatchable;

final class UserRegistered
{
    use Dispatchable;

    public function __construct(public User $user) {}
}
