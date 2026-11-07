<?php

namespace App\Offers\Partner;

use App\Cars\Jobs\ReadCarDocs;
use App\Mail\Jobs\ImportThreadFiles;
use App\Mail\Thread;
use App\Offers\Actions\UpdateOffer;
use App\Offers\Offer;

/**
 * Поставщик отправил цену (07.11.2026; с 08.11.2026 — кнопкой «Отправить», `SendPrices`) или наши двинули черновик дальше:
 * предложение ушло из его списка — дальше оно наше, и автоматика, что ждала, работает как
 * обычно: ветки писем и лот Мигторга по номеру и VIN (правка поставщика их не тянет — `UpdateOffer`), чтение документов
 * (и его файлов: сами они не читаются — `AppServiceProvider`). Черновику, который он завёл сам (`PartnerOffers::held`), —
 * ещё файлы писем, что ждали. Поставщик этого уже не видит.
 */
final class Handover
{
    public static function after(Offer $offer, bool $wasHeld): void
    {
        $offer->refresh();
        if (PartnerOffers::listed(PartnerOffers::eligible(Offer::whereKey($offer->id)))->exists()) {
            return;
        }
        UpdateOffer::identityChanged($offer, ['claim_ref', 'vin']);
        ReadCarDocs::dispatch((int) $offer->car_id);
        if ($wasHeld) {
            Thread::where('offer_id', $offer->id)->pluck('id')->each(fn ($id) => ImportThreadFiles::dispatch($id));
        }
    }
}
