<?php

namespace App\Offers\Actions;

use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Offers\Slots;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * «Опубликовать» с выбором (владелец 03.10.2026): сейчас — как раньше, иначе в слот 16:00 — ближайший или следующий.
 * Готовность проверяется сразу, а не в 16:00; срок приёма не хранится — его посчитает публикация от слота.
 */
final class ScheduleOffer
{
    public function __construct(private ChangeOfferState $change) {}

    /** `batch` — пачкой из «Оцененных»: «сейчас» без уведомления на каждое, часы соберут одно «Опубликовано N». */
    public function __invoke(Offer $offer, string $when, User $by, bool $batch = false): Offer
    {
        if (! in_array($offer->state, [OfferState::Draft, OfferState::Gallery], true)) {
            throw ValidationException::withMessages(['state' => 'Опубликовать можно черновик или галерею']);
        }
        $at = Slots::at($when);
        if (! $at) {
            return ($this->change)($offer, OfferState::Open, $by, batch: $batch);
        }
        ChangeOfferState::assertReady($offer);
        $offer->forceFill(['slot_at' => $at, 'slot_by' => $by->id])->save();
        $offer->log(OfferEventType::Scheduled, $by, ['at' => $at->toDateTimeString()]);

        return $offer;
    }
}
