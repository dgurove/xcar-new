<?php

namespace App\Offers\Events;

use App\Users\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Менеджер открыл предложения покупателям. `fresh` — кому какие офферы стали
 * видны впервые: id покупателя → номера офферов; уведомление идёт только им.
 *
 * @param  list<int>  $offerIds
 * @param  array<int, list<int>>  $fresh
 */
final class OffersShown
{
    use Dispatchable;

    public function __construct(public User $manager, public array $offerIds, public array $fresh) {}
}
