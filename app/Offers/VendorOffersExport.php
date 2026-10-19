<?php

namespace App\Offers;

use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * «Скачать» в «Предложениях» вендора CRM (15.10.2026, просьба Т-Страхования: номера убытка с интересом или хоть одним
 * подтверждением): строка на предложение — номер убытка первым, где оно сейчас словом вкладки, сколько было
 * подтверждений и интереса, лучшая цена подтверждения, цены и даты, когда номер отправили вендору в запрос.
 */
final class VendorOffersExport
{
    public const HEAD = ['№ убытка', '№ предложения', 'Марка, модель', 'Год', 'VIN', 'Где сейчас', 'Подтверждений', 'Лучшее подтверждение, ₽',
        'Интересов', 'Закупочная, ₽', 'Цена продажи, ₽', 'Срок обязывающего', 'Заведено', 'Вышло в продажу', 'Запрос отправлен'];

    /** @param  Collection<int, Offer>  $offers  с `bids_count`, `interests_count`, `best_bid` */
    public static function write(Collection $offers, string $path): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Предложения');
        $writer->addRow(Row::fromValues(self::HEAD));
        foreach ($offers as $o) {
            $writer->addRow(Row::fromValues(array_map(fn ($x) => $x ?? '', [
                $o->claim_ref, $o->state === OfferState::Draft ? null : $o->number, $o->carRecord()->title() ?: null,
                $o->year, $o->vin, $o->crmTab(), (int) $o->bids_count ?: null, $o->best_bid ? (int) $o->best_bid : null,
                (int) $o->interests_count ?: null, $o->floor_price, $o->asking_price, $o->binding_until?->format('d.m.Y H:i'),
                $o->created_at?->format('d.m.Y'), $o->published_at?->format('d.m.Y'), $o->claimRequest?->created_at?->format('d.m.Y'),
            ])));
        }
        $writer->close();
    }
}
