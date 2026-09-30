<?php

namespace App\Telegram;

use App\Offers\Offer;
use Illuminate\Support\HtmlString;

/**
 * Строки сообщений Telegram про машину (решение владельца 30.09.2026): машина — в заголовке, дальше VIN, потом суть
 * (деньги, срок, что сделать), последней строкой #номер — хештег, по нему в чате находятся все сообщения одной машины.
 * VIN — в <code>: моноширинный и копируется касанием; e() и Blade пропускают HtmlString как есть, остальное экранируют.
 */
final class Text
{
    /** @return list<string|HtmlString> VIN, середина, #номер; пустые строки выпадают */
    public static function lines(?Offer $offer, ?string ...$middle): array
    {
        return array_values(array_filter([
            $offer ? self::vin($offer) : null,
            ...$middle,
            $offer?->number ? self::tag($offer) : null,
        ], fn ($l) => $l !== null && $l !== ''));
    }

    public static function vin(Offer $offer): ?HtmlString
    {
        return $offer->vin ? new HtmlString('VIN <code>'.e($offer->vin).'</code>') : null;
    }

    public static function tag(Offer $offer): string
    {
        return '#'.$offer->number;
    }
}
