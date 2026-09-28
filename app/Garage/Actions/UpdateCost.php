<?php

namespace App\Garage\Actions;

use App\Garage\Cost;
use App\Garage\Events\GarageChanged;

/** Поправить расход: что, сколько, когда. Кто платил — не меняется, это уже случилось. */
final class UpdateCost
{
    public function __invoke(Cost $cost, array $data): Cost
    {
        $cost->update(['title' => $data['title'], 'amount' => $data['amount'], 'spent_at' => $data['spent_at'] ?? $cost->spent_at]);
        GarageChanged::dispatch($cost->car);

        return $cost;
    }
}
