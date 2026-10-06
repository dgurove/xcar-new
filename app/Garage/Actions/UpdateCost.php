<?php

namespace App\Garage\Actions;

use App\Garage\Cost;
use App\Garage\Events\GarageChanged;
use App\Garage\Payer;
use App\Users\User;

/**
 * Поправить расход: что, сколько, когда. Кто платил — правит только сотрудник (кроме «Оплаты поставщику»: она
 * всегда менеджера); у машины без менеджера — только мы.
 */
final class UpdateCost
{
    public function __invoke(Cost $cost, array $data, ?User $by = null): Cost
    {
        $payer = $by?->isAdmin() && ! $cost->isSupplier() ? (Payer::tryFrom((string) ($data['paid_by'] ?? '')) ?? $cost->payer) : $cost->payer;
        if ($payer === Payer::Manager && ! $cost->car->manager_id) {
            $payer = Payer::Xcar;
        }
        $cost->update(['title' => $data['title'], 'amount' => $data['amount'], 'spent_at' => $data['spent_at'] ?? $cost->spent_at, 'payer' => $payer]);
        $cost->car->log($by, ['do' => 'cost_edit', 'title' => $cost->title, 'amount' => $cost->amount, 'payer' => $payer->value]);
        GarageChanged::dispatch($cost->car);

        return $cost;
    }
}
