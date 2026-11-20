<?php

namespace App\Support;

use App\Offers\Offer;

/**
 * Шторка сделки поверх приложения одного раздела (`x-ui.deal-sheet`, `deal_sheet_controller`): XCAR Chat и XCAR Mail
 * (20.11.2026). Из установленного приложения в другое не уйти (iPhone открыл бы Safari, где человек не вошёл), поэтому
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

    /** Машина CRM: в XCAR Mail — страница CRM целиком во фрейм шторки, в CRM — свой путь. */
    public static function crm(Offer $offer): string
    {
        return self::here() ? $offer->crmUrl() : $offer->crmPath();
    }
}
