<?php

namespace App\Garage\Actions;

use App\Garage\Car;
use App\Garage\Cost;
use App\Garage\Payer;
use App\Users\User;

/** Расход по машине: что, сколько, когда. Платил тот, кто записал: менеджер — из своего, мы — наш. */
final class AddCost
{
    public function __invoke(Car $car, array $data, User $by): Cost
    {
        return $car->costs()->create([
            'title' => $data['title'],
            'amount' => $data['amount'],
            'spent_at' => $data['spent_at'] ?? now()->toDateString(),
            'payer' => $by->isStaff() ? Payer::Xcar : Payer::Manager,
            'created_by' => $by->id,
        ]);
    }
}
