<?php

namespace App\Workflow\Actions;

use App\Offers\Offer;
use App\Users\User;
use App\Workflow\Track;
use Illuminate\Support\Facades\DB;

/**
 * Поставить оффер на начало маршрутов его страховой. Ветка, на которой оффер
 * уже стоит, не трогается: смена страховой не отматывает сделанную работу.
 */
final class StartRoute
{
    public function __construct(private EnterStage $enter) {}

    public function __invoke(Offer $offer, ?User $by = null): Offer
    {
        $insurer = $offer->insurer;
        if (! $insurer || ! $insurer->is_active) {
            return $offer;
        }
        foreach (Track::cases() as $track) {
            if ($offer->position($track)) {
                continue;
            }
            $workflow = $insurer->workflow($track);
            if (! $workflow?->is_active || ! ($start = $workflow->startStage())) {
                continue;
            }
            $offer = DB::transaction(fn () => ($this->enter)($offer, $start, $by));
        }

        return $offer;
    }
}
