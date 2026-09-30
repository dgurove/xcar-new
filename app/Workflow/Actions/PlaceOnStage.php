<?php

namespace App\Workflow\Actions;

use App\Offers\Offer;
use App\Users\User;
use App\Workflow\Stage;
use Illuminate\Support\Facades\DB;

/** Поставить оффер на любой этап руками — решение человека, мимо исходов. */
final class PlaceOnStage
{
    public function __construct(private EnterStage $enter) {}

    public function __invoke(Offer $offer, Stage $stage, User $by, bool $back = false): Offer
    {
        return DB::transaction(function () use ($offer, $stage, $by, $back) {
            $offer = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();

            return ($this->enter)($offer, $stage, $by, back: $back);
        });
    }
}
