<?php

namespace App\Offers;

use App\Media\Jobs\StampPhotos;

/**
 * Запрет шеринга и знак на фото на время оценки менеджером (11.10.2026). Держится, пока хоть один менеджер ещё
 * оценивает (просьба `asked`): оценил или отказался — ему делать нечего, и запрет снимается сразу, не дожидаясь цены
 * продажи (13.10.2026, начальник: «он оценил, а висит „Запретить шеринг“»). Оценку отклонили — менеджер оценивает
 * снова, запрет возвращается. Ставила оценка — помнит (`share_locked_by_estimate`); поставленный админом не трогает.
 */
final class EstimateLock
{
    public static function lock(Offer $offer): void
    {
        if ($offer->share_locked) {
            return;
        }
        $offer->forceFill(['share_locked' => true, 'share_locked_by_estimate' => true])->save();
        StampPhotos::dispatch($offer->carRecord());
    }

    public static function release(Offer $offer): void
    {
        $offer->refresh();
        if (! $offer->share_locked_by_estimate || $offer->estimates()->where('state', EstimateState::Asked)->exists()) {
            return;
        }
        $offer->forceFill(['share_locked' => false, 'share_locked_by_estimate' => false])->save();
        StampPhotos::dispatch($offer->carRecord());
    }
}
