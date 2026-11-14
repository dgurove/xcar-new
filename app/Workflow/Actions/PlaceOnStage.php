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

    /** @param  array<string, mixed>  $payload  как у `EnterStage`: `label` — подпись в «Истории», `quiet` — без уведомлений */
    public function __invoke(Offer $offer, Stage $stage, User $by, bool $back = false, array $payload = []): Offer
    {
        return DB::transaction(function () use ($offer, $stage, $by, $back, $payload) {
            $offer = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();

            return ($this->enter)($offer, $stage, $by, $payload, back: $back);
        });
    }
}
