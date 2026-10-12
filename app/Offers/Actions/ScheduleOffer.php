<?php

namespace App\Offers\Actions;

use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Offers\Slots;
use App\Users\User;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * «Опубликовать» с выбором (владелец 03.10.2026): сейчас — как раньше, иначе в слот 16:00 — ближайший или следующий.
 * Готовность проверяется сразу, а не в 16:00; срок приёма не хранится — его посчитает публикация от слота.
 */
final class ScheduleOffer
{
    public function __construct(private ChangeOfferState $change) {}

    /** `batch` — пачкой из «Оцененных»: «сейчас» без уведомления на каждое, часы соберут одно «Опубликовано N». */
    public function __invoke(Offer $offer, string $when, User $by, bool $batch = false, ?Carbon $until = null): Offer
    {
        if (! in_array($offer->state, [OfferState::Draft, OfferState::Gallery], true)) {
            throw ValidationException::withMessages(['state' => 'Опубликовать можно черновик или галерею']);
        }
        // «Срочно» (12.10.2026): сейчас, свой срок приёма, всем сразу (`SyncViewers`), громкое уведомление
        // (`NotifyViewers` → `UrgentOfferNotice`). Срок и метка — записью до перехода: публикация будущий срок не трогает,
        // волны и рассылка читают метку.
        if ($when === Slots::URGENT) {
            ChangeOfferState::assertReady($offer);
            $offer->forceFill(['bids_close_at' => $until ?? Slots::urgentDefault(), 'urgent_at' => now(), 'urgent_by' => $by->id])->save();

            return ($this->change)($offer, OfferState::Open, $by);
        }
        $at = Slots::at($when);
        if (! $at) {
            return ($this->change)($offer, OfferState::Open, $by, batch: $batch);
        }
        ChangeOfferState::assertReady($offer);
        $offer->forceFill(['slot_at' => $at, 'slot_by' => $by->id])->save();
        $offer->log(OfferEventType::Scheduled, $by, ['at' => $at->toDateTimeString()]);
        // Отправили в продажу — корректировки менеджеров больше не нужны (владелец 11.10.2026: «можно и пока не ответили»).
        app(CloseEstimates::class)($offer, $by);

        return $offer;
    }
}
