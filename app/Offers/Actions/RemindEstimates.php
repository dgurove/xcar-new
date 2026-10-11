<?php

namespace App\Offers\Actions;

use App\Notifications\EstimateDueNotice;
use App\Offers\Estimate;
use Throwable;

/** Шаг `offers:tick`: за шесть часов до срока — одно напоминание менеджеру, который ещё не оценил. */
final class RemindEstimates
{
    public function due(): int
    {
        $n = 0;
        Estimate::waiting()->whereNull('reminded_at')->where('due_at', '<=', now()->addHours(Estimate::REMIND_HOURS))->where('due_at', '>', now())
            ->with('offer', 'user')->lazyById()->each(function (Estimate $estimate) use (&$n) {
                try {
                    $estimate->update(['reminded_at' => now()]);
                    $estimate->user?->notify(new EstimateDueNotice($estimate));
                    $n++;
                } catch (Throwable $e) {
                    report($e);
                }
            });

        return $n;
    }
}
