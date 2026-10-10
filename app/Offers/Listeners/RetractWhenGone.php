<?php

namespace App\Offers\Listeners;

use App\Offers\Events\OfferStateChanged;
use App\Offers\Jobs\RetractOfferNotices;
use App\Offers\OfferState;

/** Опубликованное ушло из продажи (сделка, снято, архив) — анонсы тем, кому оно больше не открыто, убираются. */
final class RetractWhenGone
{
    public function handle(OfferStateChanged $e): void
    {
        if ($e->offer->published_at && $e->offer->state !== OfferState::Open) {
            RetractOfferNotices::dispatch($e->offer->id);
        }
    }
}
