<?php

namespace App\Offers\Actions;

use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Users\User;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Часы (offers:tick, первым шагом): наступил слот — выпускаем по одному, от имени поставившего. Чего-то не хватает
 * (сняли фото, стёрли цену) — слот снимается и причина пишется в «Историю», иначе часы бились бы каждую минуту.
 * Сбой базы — в лог, слот остаётся: выйдет следующим тиком.
 */
final class PublishDueSlots
{
    public function __construct(private ChangeOfferState $change) {}

    public function __invoke(): int
    {
        $published = 0;
        foreach (Offer::scheduled()->where('slot_at', '<=', now())->orderBy('slot_at')->orderBy('id')->get() as $offer) {
            try {
                $by = $offer->slot_by ? User::find($offer->slot_by) : null;
                ($this->change)($offer, OfferState::Open, $by, at: $offer->slot_at);
                $published++;
            } catch (ValidationException $e) {
                $offer->forceFill(['slot_at' => null, 'slot_by' => null])->save();
                $offer->log(OfferEventType::Scheduled, null, ['error' => collect($e->errors())->flatten()->first()]);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $published;
    }
}
