<?php

namespace App\Park;

use App\Offers\Offer;
use App\Offers\OfferState;
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
     * Продана не через нас (13.11.2026, владелец: «есть заявка на выдачу — тачка уже продана, нет смысла заводить, и даже
     * заведённую надо убирать, даже опубликованную; в сделке — не убирать, главное не выйти на самих себя»): у ТС заявка на
     * выдачу (любого состояния) или она уже выдана, а выдача не наша — у заявки нет сделки XCar (`deal_id`, ставят
     * `SyncParkRelease`) и продажа машины не в сделке и не в гараже.
     */
    public static function soldElsewhere(Vehicle $vehicle): bool
    {
        $releases = fn () => Request::withoutGlobalScope(City::SCOPE)->where('vehicle_id', $vehicle->id)->where('type', RequestType::Release);
        if ($vehicle->state !== VehicleState::Released && ! $releases()->exists()) {
            return false;
        }
        if ($releases()->whereNotNull('deal_id')->exists()) {
            return false;
        }
        $offer = $vehicle->car_id ? Offer::withoutGlobalScopes()->where('car_id', $vehicle->car_id)->latest('id')->first() : null;

        return ! ($offer && (in_array($offer->state, [OfferState::Sold, OfferState::Garage, OfferState::Delivered], true)
            || $offer->deal()->exists() || $offer->garageCar()->exists()));
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
