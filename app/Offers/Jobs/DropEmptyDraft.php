<?php

namespace App\Offers\Jobs;

use App\Offers\Offer;
use App\Support\Nav;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * Из пустого черновика ушли (`OfferController::dropEmpty`) — через двадцать секунд его нет. Не сразу: уход со страницы
 * браузер шлёт и при её обновлении, а открытый снова редактор отмечает черновик (`updated_at`) — такой остаётся.
 * Внесли хоть что-то, загрузили фото, пришли письма — `emptyDraft` его не отдаст.
 */
final class DropEmptyDraft implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $offerId, public Carbon $leftAt)
    {
        $this->delay(now()->addSeconds(20));
    }

    public function handle(): void
    {
        // updated_at хранится до секунды: открыли снова в ту же секунду, что ушли, — тоже «открыли снова».
        $offer = Offer::emptyDraft()->whereKey($this->offerId)->where('updated_at', '<', $this->leftAt->copy()->startOfSecond())->first();
        if ($offer) {
            $offer->delete();
            Nav::forgetStaffCounts();
        }
    }
}
