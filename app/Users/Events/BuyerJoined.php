<?php

namespace App\Users\Events;

use App\Users\Invite;
use App\Users\User;
use Illuminate\Foundation\Events\Dispatchable;

/** Покупатель прошёл по пригласительной ссылке и зарегистрировался. */
final class BuyerJoined
{
    use Dispatchable;

    public function __construct(public User $user, public Invite $invite) {}
}
