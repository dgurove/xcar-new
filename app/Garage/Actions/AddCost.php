<?php

namespace App\Garage\Actions;

use App\Garage\Car;
use App\Garage\Cost;
use App\Garage\Events\GarageChanged;
use App\Garage\Payer;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * Расход по машине: что, сколько, когда. Менеджер пишет свой — из своего кармана, пока машина у него. Сотрудник —
 * с принятия (наш эвакуатор) и выбирает, кто платил (06.10.2026: «добавлять, убирать, корректировать» — и расходы
 * менеджера с его слов); без выбора — мы.
 */
final class AddCost
{
    public function __invoke(Car $car, array $data, User $by): Cost
    {
        if ($car->isFrozen()) {
            throw ValidationException::withMessages(['amount' => 'По ТС выставлен счёт: сначала аннулируйте его']);
        }
        $staff = $by->isAdmin();
        if (! $staff && ! $car->state->isWorking()) {
            throw ValidationException::withMessages(['amount' => 'Расходы — когда машина у вас']);
        }
        $payer = $staff ? (Payer::tryFrom((string) ($data['paid_by'] ?? '')) ?? Payer::Xcar) : Payer::Manager;
        if ($payer === Payer::Manager && ! $car->manager_id) {
            $payer = Payer::Xcar;
        }
        $cost = $car->costs()->create([
            'title' => $data['title'],
            'amount' => $data['amount'],
            'spent_at' => $data['spent_at'] ?? now()->toDateString(),
            'payer' => $payer,
            'created_by' => $by->id,
        ]);
        $car->log($by, ['do' => 'cost', 'title' => $cost->title, 'amount' => $cost->amount, 'payer' => $payer->value]);
        GarageChanged::dispatch($car);

        return $cost;
    }
}
