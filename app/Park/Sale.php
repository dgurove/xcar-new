<?php

namespace App\Park;

use App\Offers\Offer;
use App\Users\User;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * ТС парковки в продаже. Машина одна (`Cars\Car`, 14.10.2026): у ТС и её предложения одна запись машины — тождество,
 * фото и документы общие, зеркал и переносов файлов нет. Здесь — запись в истории ТС, когда продажа кончилась, и метка
 * файлов парковки. Правило закупочной от оценочной — `Offers\Pricing`.
 */
final class Sale
{
    /**
     * Продажа кончилась — предложение в архиве, снято или удаляется: в истории ТС «Снята с продажи». Машина, её кадры и
     * документы остаются у машины; парковку больше ничего не трогает.
     */
    public static function end(Offer $offer, ?User $by = null): void
    {
        $vehicle = $offer->car_id ? Vehicle::withoutGlobalScope(City::SCOPE)->where('car_id', $offer->car_id)->first() : null;
        $vehicle?->log(EventType::Unlinked, $by ?? auth()->user(), ['number' => $offer->number]);
    }

    /**
     * Файл парковки, а не продажи (снят на парковке, пришёл письмом её ящика — метка `park`, её ставит дорожка парковки,
     * `Vehicle::carMediaMark`): CRM его прячет, но не удаляет.
     */
    public static function parkOwned(Media $media): bool
    {
        return (bool) $media->getCustomProperty('park');
    }
}
