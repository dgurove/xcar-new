<?php

namespace App\Telegram\Offers;

use App\Cars\Fuel;
use App\Offers\Offer;
use App\Offers\PriceView;
use App\Support\Liters;
use App\Support\Surface;
use App\Users\User;

/**
 * Карточка предложения в боте — подпись к главному фото слово в слово как у владельца (03.10.2026):
 *
 *   Поступило новое предложение (и ещё 16)
 *
 *   Volkswagen Taro, 2022, Москва
 *   Привод передний; пробег 27 000; двигатель бензиновый; объём двигателя 1,4
 *   1 458 000 → 1 590 000
 *
 * Цена — как менеджеру на сайте (`PriceView`: заявленная → продажи), закупочной нет. Чего у предложения нет — того
 * нет и в строке. Числа — обычными пробелами: неразрывные Telegram показывает как есть, но копируются они криво.
 */
final class Card
{
    public static function caption(Offer $offer, User $user, int $more): string
    {
        $title = $offer->titleWithYear().($offer->settlement ? ', '.$offer->settlement->name : '');
        $facts = array_values(array_filter([
            $offer->drive ? 'Привод '.mb_strtolower($offer->drive->label()) : null,
            $offer->mileage ? 'пробег '.self::nums($offer->mileage) : null,
            $offer->fuel ? 'двигатель '.self::fuel($offer->fuel) : null,
            $offer->engine_volume ? 'объём двигателя '.Liters::format($offer->engine_volume) : null,
        ]));
        if ($facts) {
            $facts[0] = mb_strtoupper(mb_substr($facts[0], 0, 1)).mb_substr($facts[0], 1);
        }
        $price = PriceView::for($offer, $user);
        $lines = [
            'Поступило новое предложение'.($more > 0 ? ' (и ещё '.$more.')' : ''),
            '',
            $title,
            $facts ? implode('; ', $facts) : null,
            $price->shown() ? ($price->withFrom() ? self::nums($price->from).' → ' : '').self::nums($price->to) : null,
        ];

        return e(implode("\n", array_filter($lines, fn ($l) => $l !== null)));
    }

    /** «Открыть в XCar» под фото: страница предложения на сайте. */
    public static function buttons(Offer $offer, bool $login): array
    {
        return Keys::inline([[Keys::site('Открыть в XCar', self::url("/offers/{$offer->number}", $login), $login)]]);
    }

    /**
     * Адрес на сайте: через вход по кнопке Telegram (`/telegram/open/…` проверит подпись и впустит) или прямой.
     * Цель — короткими словами, а не путём: подпись Telegram не покрывает наш параметр, путь — не перенаправление.
     */
    public static function url(string $path, bool $login): string
    {
        if (! $login) {
            return Surface::Site->url($path);
        }
        $target = match (true) {
            $path === '/offers' => 'offers',
            $path === '/account/favorites' => 'favorites',
            $path === '/account/invites' => 'invites',
            (bool) preg_match('~^/offers/(\d+)$~', $path, $m) => $m[1],
            default => 'offers',
        };

        return Surface::Site->url('/telegram/open/'.$target);
    }

    public static function nums(?int $value): string
    {
        return number_format((int) $value, 0, '', ' ');
    }

    private static function fuel(Fuel $fuel): string
    {
        return match ($fuel) {
            Fuel::Petrol => 'бензиновый',
            Fuel::Diesel => 'дизельный',
            Fuel::Hybrid => 'гибридный',
            Fuel::Electric => 'электрический',
            Fuel::Gas => 'газовый',
        };
    }
}
