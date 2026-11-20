<?php

namespace App\Http\Middleware;

use App\Support\Surface;
use App\Users\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Один вход — два аккаунта (02.11.2026, владелец: Сергей «как бы в одном аккаунте, но на xcar — только то, что он видит как
 * менеджер, а на park — как управляющий»): вошёл любым из связанных (`LinkAccounts`), а на хосте, который дом второго,
 * запрос идёт от второго. Сессия не трогается — в ней тот, кто входил; выход выходит из обоих. Стоит до `ResolveSurface`:
 * стена «сюда только сотрудникам» и уход в свой дом смотрят уже на нужного. Под «Войти как» не подменяет.
 */
class UseLinkedAccount
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if ($user?->linked_user_id && ! $request->session()->has(Impersonation::SESSION)) {
            $surface = Surface::fromHost($request->getHost());
            if (Surface::homeFor($user) !== $surface && ($linked = $user->linked) && Surface::homeFor($linked) === $surface) {
                Auth::setUser($linked);
            } elseif ($surface->single() && ! $surface->opensFor($user) && ($linked = $user->linked) && $surface->opensFor($linked)) {
                // XCAR Chat и XCAR Mail (20.11.2026) ничей не дом: вошли аккаунтом без чатов или почты (управляющий), а они у второго — работает он.
                Auth::setUser($linked);
            }
        }

        return $next($request);
    }
}
