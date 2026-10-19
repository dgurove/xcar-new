<?php

namespace App\Offers;

use App\Media\Jobs\StampPhotos;

/**
 * Знак на фото и запрет шеринга на время оценки менеджером (11.10.2026). Запрет — личный (19.10.2026, владелец: «для
 * других висит „Запретить шеринг“, это только для него»): галку «Запретить шеринг» оценка не ставит, закрыт шеринг только
 * тому, кто ещё оценивает (`Offer::shareLockedFor`). Знак на кадрах — общий, файл у машины один: держится, пока хоть один
 * менеджер оценивает (просьба `asked`), помнит его `share_locked_by_estimate` (`Car::shareLocked`). Оценил или отказался —
 * снимается сразу; оценку отклонили — менеджер оценивает снова, знак возвращается.
 */
final class EstimateLock
{
    public static function lock(Offer $offer): void
    {
        if ($offer->share_locked_by_estimate) {
            return;
        }
        $offer->forceFill(['share_locked_by_estimate' => true])->save();
        StampPhotos::dispatch($offer->carRecord());
    }

    public static function release(Offer $offer): void
    {
        $offer->refresh();
        if (! $offer->share_locked_by_estimate || $offer->estimates()->where('state', EstimateState::Asked)->exists()) {
            return;
        }
        $offer->forceFill(['share_locked_by_estimate' => false])->save();
        StampPhotos::dispatch($offer->carRecord());
    }
}
