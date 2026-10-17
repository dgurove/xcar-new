<?php

namespace App\Logistics\Actions;

use App\Logistics\Transport;
use App\Users\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Деньги логиста — отметкой, без счетов (18.10.2026, владелец: «цена и кто должен»): заказчик заплатил ей
 * (`client_paid_at`, только у её заявки) или она заплатила исполнителю (`executor_paid_at`, у любой её задачи — и у вывоза
 * XCar, где с исполнителем она рассчитывается сама). Повторно — снять отметку. Отмечает только сама логист.
 */
final class MarkPaid
{
    public const SIDES = ['client' => 'client_paid_at', 'executor' => 'executor_paid_at'];

    /** @param  Collection<int, Transport>|Transport  $tasks */
    public function __invoke(Collection|Transport $tasks, string $side, bool $paid, User $by): int
    {
        $column = self::SIDES[$side] ?? throw ValidationException::withMessages(['side' => 'Кто платит']);
        $n = 0;
        foreach ($tasks instanceof Transport ? collect([$tasks]) : $tasks as $t) {
            if (! $t->isCoordinatedBy($by) || ($side === 'client' && ! $t->isOrder())) {
                abort(404);
            }
            if (($t->{$column} !== null) !== $paid) {
                $t->forceFill([$column => $paid ? now() : null])->save();
                $n++;
            }
        }

        return $n;
    }
}
