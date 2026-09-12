<?php

namespace App\Users\Events;

use App\Users\User;
use Illuminate\Foundation\Events\Dispatchable;

final class AccessDecided
{
    use Dispatchable;

    public function __construct(public User $user, public bool $approved, public ?User $by = null) {}
}
