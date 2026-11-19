<?php

namespace App\Offers\Actions;

use App\Offers\Binding;
use App\Offers\Events\OfferPublished;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Support\Ago;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Менеджер отказался» → «Вернуть в продажу…» (19.11.2026, начальник): приём на выбранные часы (по умолчанию 24) с этой минуты,
 * волны показа заново, «Новое предложение»; никто не подтвердит за это время — в архив и поставщику «не актуально»
 * (`lapse_since`, `LapseRefused`). Резервные подтверждения остаются живыми — их по-прежнему можно принять. `keep` — «Не снимать
 * автоматически»: авто-снятие гаснет, дальше как обычная продажа.
 */
final class ResaleRefused
{
    public const HOURS = [6, 12, 24, 48];

    public function __invoke(Offer $offer, int $hours, User $by): Offer
    {
        $offer = DB::transaction(function () use ($offer, $hours, $by) {
            $offer = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            if ($offer->state !== OfferState::Open || ! $offer->refused_at || $offer->lapse_since) {
                throw ValidationException::withMessages(['resale' => 'Вернуть так уже нельзя']);
            }
            $offer->forceFill(['bids_close_at' => Binding::clampClose($offer, now()->addHours($hours)), 'published_at' => now(), 'lapse_since' => now()])->save();
            app(SyncViewers::class)($offer);
            $offer->log(OfferEventType::Note, $by, ['text' => 'Вернули в продажу до '.Ago::at($offer->bids_close_at, true, words: false).'. Если никто не подтвердит, снимется, поставщику уйдёт «не актуально»']);

            return $offer;
        });
        OfferPublished::dispatch($offer, $by, false);

        return $offer;
    }

    /** «Не снимать автоматически» — авто-снятие гаснет. */
    public function keep(Offer $offer, User $by): void
    {
        if (! $offer->lapse_since) {
            return;
        }
        $offer->forceFill(['lapse_since' => null])->save();
        $offer->log(OfferEventType::Note, $by, ['text' => 'Не снимать автоматически']);
    }
}
