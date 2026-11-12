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
    /** @return array{total: ?float, debt: float, unbilled: float} */
    public static function of(Vehicle $vehicle, User $user): array
    {
        if (! $user->parkMoney()) {
            return ['total' => null, 'debt' => 0, 'unbilled' => 0];
        }

        return [
            'total' => Accrual::totals([$vehicle])[$vehicle->id] ?? null,
            // «Долг» — неоплаченные счета; набежавшее стоит рядом отдельным числом.
            'debt' => Ledger::vehicleDebt($vehicle),
            // Набежало и не выставлено (с дня после `storage_billed_until`) — шторка «Хранение» и кнопка «Выставить счёт».
            'unbilled' => $vehicle->accepted_at ? round((float) Accrual::storage($vehicle)->sum('amount'), 2) : 0,
        ];
    }
}
