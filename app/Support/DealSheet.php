<?php

namespace App\Support;

use App\Offers\Deal;
use App\Offers\Offer;
use App\Offers\OfferNumber;

/**
 * Шторка сделки поверх приложения одного раздела (`x-ui.deal-sheet`, `deal_sheet_controller`): XCAR Chat и XCAR Mail
 * (20.11.2026), XCAR Pay (22.11.2026). Из установленного приложения в другое не уйти (iPhone открыл бы Safari, где человек не вошёл), поэтому
 * ссылка на машину грузит во фрейм шторки настоящую страницу CRM или сайта; вход общий (cookie `.xcar.ru`).
 */
final class DealSheet
{
    /** Есть ли шторка на этом хосте. */
    public static function here(): bool
    {
        return Surface::current()->single();
    }

    /** Атрибуты ссылки, что открывает шторку; на других хостах — ничего, ссылка ведёт как обычно. */
    public static function attrs(): string
    {
        return self::here() ? 'data-deal-sheet data-turbo="false"' : '';
    }

    /**
     * Атрибуты ссылки карточки на страницу CRM (путь `/offers/N`, `/work/deals/N`): в приложении одного раздела — шторкой с полным
     * адресом CRM (XCAR Pay, 22.11.2026: в карточке ссылки «ТС», «Сделка», «Счёт»), в CRM — её путём во весь экран из окна.
     */
    public static function crmLink(string $path): string
    {
        return self::here()
            ? 'href="'.e(Surface::Crm->url($path)).'" data-deal-sheet data-turbo="false"'
            : 'href="'.e($path).'" data-turbo-frame="_top"';
    }

    /**
     * Шторка, открытая сразу по адресу (XCAR Pay, 22.11.2026: ссылки уведомлений `?deal=` / `?car=`, `NoticeLink::pay`): сотруднику
     * со сделками — страница CRM, менеджеру — его сделка на сайте (чужая — ничего). Вне приложений одного раздела — null.
     */
    public static function requested(): ?string
    {
        $user = auth()->user();
        if (! $user || ! self::here()) {
            return null;
        }
        $staff = $user->canEditDeals();
        if (ctype_digit($id = (string) request()->query('deal')) && ($deal = Deal::find((int) $id))) {
            return $staff ? Surface::Crm->url('/work/deals/'.$deal->id) : ($deal->buyer_id === $user->id ? self::site($deal->href()) : null);
        }
        if (($offer = OfferNumber::find((string) request()->query('car'))) !== null) {
            if ($staff) {
                return $offer->crmUrl();
            }
            $deal = Deal::where('offer_id', $offer->id)->where('buyer_id', $user->id)->latest('id')->first();

            return $deal ? self::site($deal->href()) : null;
        }

        return null;
    }

    private static function site(string $path): string
    {
        return str_starts_with($path, 'http') ? $path : Surface::Site->url($path);
    }

    /** Машина CRM: в XCAR Mail — страница CRM целиком во фрейм шторки, в CRM — свой путь. */
    public static function crm(Offer $offer): string
    {
        return self::here() ? $offer->crmUrl() : $offer->crmPath();
    }
}
