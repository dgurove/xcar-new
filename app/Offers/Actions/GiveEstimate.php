<?php

namespace App\Offers\Actions;

use App\Offers\Estimate;
use App\Offers\EstimateState;
use App\Offers\Events\EstimateGiven;
use App\Offers\OfferEventType;
use App\Support\Nav;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * Менеджер оценил: оценка — ориентир админу под полем цены продажи, не цена. Поправить можно, пока админ не решил;
 * просроченная оценка тоже принимается — просьба висит, пока её не закроют.
 */
final class GiveEstimate
{
    public function __invoke(Estimate $estimate, int $amount, User $by): Estimate
    {
        if (! $estimate->isOpen() || $estimate->user_id !== $by->id) {
            throw ValidationException::withMessages(['amount' => 'Оценка уже не нужна']);
        }
        if ($amount < 1000) {
            throw ValidationException::withMessages(['amount' => 'Укажите цену в рублях']);
        }
        if ($estimate->isGiven() && $estimate->amount === $amount) {
            return $estimate;
        }
        $estimate->update(['state' => EstimateState::Given, 'amount' => $amount, 'estimated_at' => now()]);
        $estimate->offer->log(OfferEventType::Estimate, $by, ['do' => 'given', 'amount' => $amount]);
        Nav::forgetStaffCounts();
        EstimateGiven::dispatch($estimate, $by);

        return $estimate;
    }
}
