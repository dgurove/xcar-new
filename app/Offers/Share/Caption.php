<?php

namespace App\Offers\Share;

use App\Offers\Offer;
use App\Offers\OfferNumber;
use App\Offers\PriceView;
use App\Purchases\Car;
use App\Support\Surface;
use App\Users\User;

/**
 * Строки для текста в мессенджер: ключ, подпись, значение, включено ли по
 * умолчанию. Собирает клиент из отмеченных — цена одной строкой, метка НДС в конце.
 */
final class Caption
{
    /** @return list<array{key: string, label: string, value: string, on: bool, group: ?string}> */
    public static function fields(Offer $offer, ?User $user): array
    {
        $money = fn ($v) => $v ? number_format($v, 0, '', ' ') : null;
        $staff = $user?->isAdmin() ?? false;
        $price = PriceView::for($offer, $user);
        // Цены — последней строкой: клиент собирает отмеченные сверху вниз, цены склеивает стрелкой «от → до» в конце.
        // VIN — как этому человеку на сайте (`Offer::vinFor`): скрытый уходит маской.
        $rows = [
            // Номер с датой — первой строкой с решёткой (`#2610041229`); черновику его выдаёт `Subject::offer` при шеринге.
            ['number', 'Номер', OfferNumber::isPublic($offer) ? '#'.$offer->number : null, true],
            ['dl', 'ДЛ', $offer->leaseRef() ? 'ДЛ '.$offer->leaseRef() : null, true],
            ['model', 'Марка, модель, год', $offer->titleWithYear(), true],
            // VIN — сразу под машиной (владелец 05.10.2026: «вин повыше»), без слова «VIN».
            ['vin', 'VIN', $offer->vin ? ($staff ? $offer->vin : $offer->vinFor($user)) : null, true],
            ['city', 'Город', $offer->settlement?->title(), true],
            // Характеристики — одной строкой через «; » (группа `tech`, склеивает клиент): «Автоматическая, Полный; Бензин; 1,5 л; 72 000 км».
            ['specs', 'КПП, привод, кузов', implode(', ', array_filter([$offer->transmission?->label(), $offer->drive?->label(), $offer->body?->label()])) ?: null, true, 'tech'],
            // Топливо — своей галкой, по умолчанию включена (владелец 05.10.2026: «это важно»).
            ['fuel', 'Топливо', $offer->fuel?->label(), true, 'tech'],
            ['engine', 'Двигатель', $offer->engine_volume ? number_format($offer->engine_volume / 1000, 1, ',', '').' л'.($offer->engine_power ? ', '.$offer->engine_power.' л. с.' : '') : null, true, 'tech'],
            ['mileage', 'Пробег', $offer->mileage !== null ? number_format($offer->mileage, 0, '', ' ').' км' : null, true, 'tech'],
            ['tags', 'Метки', $offer->tags ? implode(', ', $offer->tags) : null, true],
            // Строкой с глаголом, а не голой датой (владелец 04.10.2026): в тексте без подписей «07.10.2026 17:00» ни о чём не говорит.
            ['until', 'Приём подтверждений до', $offer->bids_close_at ? 'Подтвердить до '.$offer->bids_close_at->translatedFormat('d.m.Y H:i') : null, false],
            // Заявленная — «от», по умолчанию включена (владелец 05.10.2026): «779 000 → 920 000». Закупочной в тексте нет ни у кого:
            // сотруднику заявленная та же, что на сайте (пустая — закупочная вверх до тысячи).
            ['publish_price', 'Заявленная', $staff ? $money($offer->declaredPrice()) : ($price->visible ? $money($price->declared) : null), true],
            ['price', 'Цена', $price->visible ? $money($offer->asking_price) : null, true],
            // Ссылка на машину на сайте — последней строкой; из CRM включена (владелец 07.10.2026: «очень не хватает ссылки»).
            ['link', 'Ссылка', self::link($offer), Surface::current() === Surface::Crm],
        ];

        return self::rows($rows);
    }

    /** Машина закупки: ДЛ и наша цена вместо номера и цены оффера, срок — приём цен по закупке. */
    public static function car(Car $car, ?User $user): array
    {
        $money = fn ($v) => $v ? number_format($v, 0, '', ' ') : null;
        $prices = $user?->canSeePrices() ?? false;
        $rows = [
            ['number', 'Номер', str_starts_with(mb_strtoupper($car->dl), 'ДЛ') ? $car->dl : 'ДЛ '.$car->dl, true],
            ['city', 'Город', $car->settlement?->title() ?? $car->city, true],
            ['model', 'Марка, модель, год', $car->titleWithYear(), true],
            ['vin', 'VIN', $car->vin ?: null, true],
            ['specs', 'КПП, топливо', implode(', ', array_filter([$car->transmission?->label(), $car->fuel?->label()])) ?: null, true, 'tech'],
            ['engine', 'Двигатель', $car->engine_volume ? number_format($car->engine_volume / 1000, 1, ',', '').' л'.($car->engine_power ? ', '.$car->engine_power.' л. с.' : '') : null, true, 'tech'],
            ['mileage', 'Пробег', $car->mileage !== null ? number_format($car->mileage, 0, '', ' ').' км' : null, false, 'tech'],
            ['encumbrance', 'Обременения', $car->encumbrance, false],
            ['until', 'Приём цен до', $car->purchase?->offers_close_at?->translatedFormat('d.m.Y H:i'), false],
            ['price', 'Наша цена', $prices ? $money($car->price_listing) : null, true],
            ['link', 'Ссылка', $car->purchase ? Surface::Site->url("/purchases/{$car->purchase->number}/{$car->ref}") : null, Surface::current() === Surface::Crm],
        ];

        return self::rows($rows);
    }

    /** Адрес предложения на сайте — для текста и «Скопировать ссылку». */
    public static function link(Offer $offer): ?string
    {
        return OfferNumber::isPublic($offer) ? Surface::Site->url("/offers/{$offer->number}") : null;
    }

    private static function rows(array $rows): array
    {
        return array_values(array_map(fn ($r) => ['key' => $r[0], 'label' => $r[1], 'value' => (string) $r[2], 'on' => $r[3], 'group' => $r[4] ?? null],
            array_filter($rows, fn ($r) => $r[2] !== null && $r[2] !== '')));
    }

    /** Метка НДС у цены — только «с НДС»; без НДС ничего не пишем (владелец 05.10.2026). VIN в тексте — без слова «VIN». */
    public static function vatMark(Offer $offer): string
    {
        return $offer->prices_include_vat ? ' с НДС' : '';
    }
}
