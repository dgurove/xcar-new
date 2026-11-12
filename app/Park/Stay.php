<?php

namespace App\Park;

use App\Billing\Accrual;
use App\Billing\Ledger;
use App\Users\User;

/**
 * Деньги полоски срока `x-park.stay` — одним расчётом для окна «Наличия» и страницы дела ТС (12.11.2026, владелец про шапку
 * дела: «уродство лютое» — она жила своей вёрсткой, окно — по варианту D3; теперь шапка одна). Сумма за весь срок, долг
 * (неоплаченные счета) и набежавшее, но не выставленное (шторка «Хранение», «Выставить счёт»). Денег не видит — нули.
 */
final class Stay
{
    /**
     * $debt — уже посчитанный долг (страница дела берёт его из `CaseView`), чтобы не считать второй раз.
     *
     * @return array{total: ?array, debt: int|float, unbilled: int|float}
     */
    public static function of(Vehicle $vehicle, User $user, int|float|null $debt = null): array
    {
        if (! $user->parkMoney()) {
            return ['total' => null, 'debt' => 0, 'unbilled' => 0];
        }

        return [
            'total' => Accrual::totals([$vehicle])[$vehicle->id] ?? null,
            // «Долг» — неоплаченные счета; набежавшее стоит рядом отдельным числом.
            'debt' => $debt ?? Ledger::vehicleDebt($vehicle),
            // Набежало и не выставлено (с дня после `storage_billed_until`) — шторка «Хранение» и кнопка «Выставить счёт».
            'unbilled' => $vehicle->accepted_at ? round((float) Accrual::storage($vehicle)->sum('amount'), 2) : 0,
        ];
    }
}
