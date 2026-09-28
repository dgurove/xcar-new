<?php

namespace App\Garage\Actions;

use App\Garage\Car;
use App\Garage\Cost;
use App\Garage\Events\GarageChanged;
use App\Garage\Payer;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/** Расход по машине: что, сколько, когда. Платил тот, кто записал: менеджер — из своего, мы — наш. */
final class AddCost
{
    public function __invoke(Car $car, array $data, User $by): Cost
    {
        if ($car->isFrozen()) {
            throw ValidationException::withMessages(['amount' => 'По машине выставлен счёт: сначала аннулируйте его']);
        }
        $cost = $car->costs()->create([
            'title' => $data['title'],
            'amount' => $data['amount'],
            'spent_at' => $data['spent_at'] ?? now()->toDateString(),
            'payer' => $by->isStaff() ? Payer::Xcar : Payer::Manager,
            'created_by' => $by->id,
        ]);
        GarageChanged::dispatch($car);

        return $cost;
    }
}
