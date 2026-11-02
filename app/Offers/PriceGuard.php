<?php

namespace App\Offers;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * «Точно?» перед ценой продажи под вопросом (02.11.2026, владелец: Lexus RX 2610142042 — закупочная 3 200 000, админ вписал
 * продажу 1 200 000, через 18 дней опубликовал, менеджеры подтвердили по ней). Правило — `Margin::check`: в минус, наценка
 * меньше 5 %, продажа от двух закупочных. Спрашивает лист `price_guard_controller` до отправки (`/offers/price-check`),
 * сервер без согласия (`price_ok`) не пишет — ошибкой `price_check`, форма с ней сама ставит согласие на повтор.
 * Двери — человеком: цена продажи, закупочная админом, публикация; система, переоценка и файлы мимо.
 */
final class PriceGuard
{
    /** Что станет с ценами после правки `$data` (`asking_price`, `floor_price`), если правка их трогает. */
    public static function after(Offer $offer, array $data): ?array
    {
        if (! in_array($offer->state, [OfferState::Draft, OfferState::Open], true)) {
            return null;
        }
        $asking = array_key_exists('asking_price', $data) ? ($data['asking_price'] ?: null) : $offer->asking_price;
        $floor = array_key_exists('floor_price', $data) ? ($data['floor_price'] ?: null) : $offer->floor_price;
        if ($asking === $offer->asking_price && $floor === $offer->floor_price) {
            return null;
        }

        return Margin::check($asking, $floor);
    }

    /** Правка цен одного предложения: под вопросом и без согласия — ошибка у поля цены продажи. */
    public static function assert(Offer $offer, array $data, bool $ok): void
    {
        if (! $ok && ($check = self::after($offer, $data))) {
            self::fail($check);
        }
    }

    /** Цены целиком (снова в продажу, переоценка открытого): под вопросом и без согласия — ошибка. */
    public static function assertPrices(?int $asking, ?int $floor, bool $ok): void
    {
        if (! $ok && ($check = Margin::check($asking, $floor))) {
            self::fail($check);
        }
    }

    private static function fail(array $check): never
    {
        throw ValidationException::withMessages(['asking_price' => $check['title'].' '.$check['text'], 'price_check' => $check['tone']]);
    }

    /**
     * Публикация: предложения с ценой под вопросом.
     *
     * @param  iterable<Offer>  $offers
     * @return Collection<int, array{offer: Offer, tone: string, title: string, text: string}>
     */
    public static function risky(iterable $offers): Collection
    {
        return collect($offers)->map(fn (Offer $o) => ($c = Margin::check($o->asking_price, $o->floor_price)) ? ['offer' => $o] + $c : null)->filter()->values();
    }

    /** Публикация без согласия: ошибка со списком машин. */
    public static function assertPublish(iterable $offers, bool $ok): void
    {
        if ($ok || ($risky = self::risky($offers))->isEmpty()) {
            return;
        }
        $names = $risky->map(fn ($r) => $r['offer']->title().': '.$r['text'])->implode('; ');
        throw ValidationException::withMessages(['state' => 'Цена под вопросом — '.$names, 'price_check' => $risky->first()['tone']]);
    }
}
