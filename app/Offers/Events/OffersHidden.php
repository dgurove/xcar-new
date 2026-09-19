<?php

namespace App\Offers\Events;

use App\Users\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Менеджер закрыл предложение: у кого оно пропало — номера офферов по
 * покупателям, чтобы карточки ушли из их лент.
 *
 * @param  array<int, list<int>>  $gone  id покупателя → номера офферов
 */
final class OffersHidden
{
    use Dispatchable;

    public function __construct(public User $manager, public array $gone) {}
}
