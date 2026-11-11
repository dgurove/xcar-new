<?php

namespace App\Http\Middleware;

use App\Offers\Deal;
use App\Users\BuyerGroup;
use App\Users\User;
use Closure;
use Illuminate\Http\Request;

/**
 * Своё у менеджера — одна дверь на маршрутах кабинета (11.11.2026: проверка «сделка моя» стояла в 18 методах, «покупатель
 * мой» — в 11, и копии разошлись — у двух не было `isBuyer`): `own:deal` — его сделка (`deals.buyer_id`), `own:buyer` — его
 * покупатель (`{user}`), `own:group` — его группа покупателей. Чужое — 404, как у `offer.edit`: не подсказывать, что есть.
 * Условия сверх «своё» (идёт ли сделка, есть ли договор) — в самих методах.
 */
class EnsureOwn
{
    public function handle(Request $request, Closure $next, string $what)
    {
        $me = $request->user();
        $own = $me !== null && match ($what) {
            'deal' => ($deal = $request->route('deal')) instanceof Deal && $deal->buyer_id === $me->id,
            'buyer' => ($user = $request->route('user')) instanceof User && $user->manager_id === $me->id && $user->isBuyer(),
            'group' => ($group = $request->route('group')) instanceof BuyerGroup && $group->manager_id === $me->id,
            // Опечатка в маршруте — ошибка разработчика словами, а не немой UnhandledMatchError.
            default => throw new \LogicException("own:{$what} — нет такой проверки, есть own:deal, own:buyer, own:group"),
        };
        abort_unless($own, 404);

        return $next($request);
    }
}
