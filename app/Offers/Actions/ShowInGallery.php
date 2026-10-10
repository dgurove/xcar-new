<?php

namespace App\Offers\Actions;

use App\Offers\LegacyClaim;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferNumber;
use App\Offers\OfferState;
use App\Offers\Slots;
use App\Support\Nav;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * Галерея — окно показа черновика (владелец 08.10.2026: «черновики Т-Страхования копируются в галерею на 3 дня»):
 * черновик остаётся черновиком и идёт своим путём, а до 23:59 третьего дня его видят все менеджеры и покупатели и
 * оставляют интерес. Сам — часами у вендора с `to_gallery` (`due`), руками — «В галерею» в «Действиях».
 */
final class ShowInGallery
{
    public function __invoke(Offer $offer, ?User $by = null): Offer
    {
        if ($offer->state !== OfferState::Draft) {
            throw ValidationException::withMessages(['state' => 'В галерею можно только черновик']);
        }
        ChangeOfferState::readyForGallery($offer);
        OfferNumber::reserve($offer);
        $until = Slots::galleryUntil(now());
        $offer->forceFill(['gallery_from' => now(), 'gallery_until' => $until])->save();
        $offer->log(OfferEventType::Gallery, $by, ['until' => $until->toDateTimeString()]);
        Nav::forgetStaffCounts();

        return $offer;
    }

    /** «Убрать из галереи»: окно закрывается сейчас, интерес остаётся. */
    public function hide(Offer $offer, User $by): Offer
    {
        if ($offer->isGallery()) {
            $offer->forceFill(['gallery_until' => now()])->save();
            $offer->log(OfferEventType::Gallery, $by);
            Nav::forgetStaffCounts();
        }

        return $offer;
    }

    /**
     * Часы: черновики вендоров с `to_gallery`, которые в галерее ещё не были, — как только есть марка, модель и фото
     * (фото из писем приходят позже черновика). Не готов — попробуем следующим тиком.
     */
    public function due(): int
    {
        $shown = 0;
        // Черновики из таблицы Кати — сделки вне системы: менеджерам в галерею не показываем.
        LegacyClaim::offList(Offer::where('state', OfferState::Draft)->whereNull('gallery_from')->whereNotNull('brand_id')->whereNotNull('model_id')
            ->whereHas('vendor', fn ($v) => $v->where('to_gallery', true)))
            ->orderBy('id')->get()
            ->each(function (Offer $offer) use (&$shown) {
                try {
                    $this($offer);
                    $shown++;
                } catch (ValidationException) {
                    // Нет фото — ждём писем.
                }
            });

        return $shown;
    }
}
