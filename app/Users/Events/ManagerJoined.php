<?php

namespace App\Users\Events;

use App\Users\Invite;
use App\Users\User;
use Illuminate\Foundation\Events\Dispatchable;

/** Новый менеджер зарегистрировался по одноразовой ссылке админа. */
final class ManagerJoined
{
    use Dispatchable;

    public function __construct(public User $user, public Invite $invite) {}
}
