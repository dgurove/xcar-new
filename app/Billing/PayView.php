<?php

namespace App\Billing;

use App\Support\Surface;
use App\Users\User;

/**
 * Вид XCAR Pay (pay.xcar.ru, 22.11.2026, начальник: «отдельное приложение для оплат: и администраторы, и модераторы видят, что
 * по оплатам менеджеров, а менеджеры — что должны по комиссиям и ДКП и сколько заработали»). Одно приложение на всех, вид —
 * по человеку, как у XCAR Chat: сотруднику со сделками (админ, модератор) — «Оплаты» CRM `/work/money`, менеджеру — свои
 * `/account/money` (тот же экран, что на xcar.ru).
 */
final class PayView
{
    /** Сотрудник видит «Оплаты» CRM — и тогда, когда у него есть и свои сделки. */
    public static function staff(?User $user = null): bool
    {
        return (bool) ($user ?? auth()->user())?->canEditDeals();
    }

    /** Начало работы человека в XCAR Pay. */
    public static function home(?User $user = null): string
    {
        return self::staff($user) ? '/work/money' : '/account/money';
    }

    public static function here(): bool
    {
        return Surface::current() === Surface::Pay;
    }
}
