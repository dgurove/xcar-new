<?php

namespace App\Cars;

use App\Cars\Vin\Vin;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Park\Vehicle;
use App\Park\VehicleState;

/**
 * Одна машина — одна запись (владелец 05.10.2026). Номер убытка — одно событие: двух предложений или двух ТС с ним
 * нет никогда, тот же номер поднимает прежнюю запись. VIN — машина: среди живых он один, а закрытая запись (продана,
 * выдана, архив, отмена) новой не мешает — машина вернулась после нового ДТП. Предложение и его ТС парковки — одна
 * машина, друг другу они не двойники. Сторож стоит в `saving` обеих моделей, в базе — уникальные индексы.
 */
final class Identity
{
    public const CLOSED_OFFER = [OfferState::Delivered, OfferState::Cancelled, OfferState::Archived];

    public const CLOSED_VEHICLE = [VehicleState::Released, VehicleState::Cancelled];

    /** Предложение с этим номером убытка (любое) или, если номера нет, с этим VIN (живое). */
    public static function offerFor(?string $claimKey, ?string $vin, ?int $except = null): ?Offer
    {
        return self::offerByClaim($claimKey, $except) ?? self::offerByVin($vin, $except);
    }

    public static function offerByClaim(?string $claimKey, ?int $except = null): ?Offer
    {
        return $claimKey ? self::offers($except)->where('claim_ref_key', $claimKey)->first() : null;
    }

    public static function offerByVin(?string $vin, ?int $except = null): ?Offer
    {
        $vin = Vin::full($vin);

        return $vin ? self::offers($except)->where('vin', $vin)->whereNotIn('state', self::CLOSED_OFFER)->first() : null;
    }

    public static function vehicleFor(?string $refKey, ?string $vin, ?int $except = null): ?Vehicle
    {
        return self::vehicleByRef($refKey, $except) ?? self::vehicleByVin($vin, $except);
    }

    public static function vehicleByRef(?string $refKey, ?int $except = null): ?Vehicle
    {
        return $refKey ? Vehicle::withoutGlobalScopes()->when($except, fn ($q) => $q->whereKeyNot($except))->where('ref_key', $refKey)->first() : null;
    }

    public static function vehicleByVin(?string $vin, ?int $except = null): ?Vehicle
    {
        $vin = Vin::full($vin);

        return $vin ? Vehicle::withoutGlobalScopes()->when($except, fn ($q) => $q->whereKeyNot($except))->where('vin', $vin)->whereNotIn('state', self::CLOSED_VEHICLE)->first() : null;
    }

    /** Перед записью предложения: номер или VIN у другого — `IdentityTaken` с ним. */
    public static function guardOffer(Offer $offer): void
    {
        if ($offer->is_demo) {
            return;
        }
        $new = ! $offer->exists;
        if (($new || $offer->isDirty('claim_ref_key')) && ($holder = self::offerByClaim($offer->claim_ref_key, $offer->id))) {
            throw IdentityTaken::of($holder, 'claim_ref', ($offer->leaseRef() ? 'Номер ДЛ' : 'Номер убытка').' уже у '.self::offerName($holder));
        }
        // VIN проверяется и когда закрытое возвращают в работу: из архива рядом с живым двойником не поднять.
        if (! in_array($offer->state, self::CLOSED_OFFER, true) && ($new || $offer->isDirty(['vin', 'state'])) && ($holder = self::offerByVin($offer->vin, $offer->id))) {
            throw IdentityTaken::of($holder, 'vin', 'VIN уже у '.self::offerName($holder));
        }
        // Правка уйдёт и в ТС парковки (`Sale::toVehicle`) — номер или VIN другой ТС не встанет и там: проверяем до записи,
        // а не на полпути.
        if (! $new && $offer->isDirty(['claim_ref_key', 'vin']) && ($vehicle = Vehicle::withoutGlobalScopes()->where('offer_id', $offer->id)->first())) {
            if ($offer->isDirty('claim_ref_key') && ($holder = self::vehicleByRef($offer->claim_ref_key, $vehicle->id))) {
                throw IdentityTaken::of($holder, 'claim_ref', 'Номер убытка уже у ТС '.self::vehicleName($holder));
            }
            if ($offer->isDirty('vin') && ! in_array($vehicle->state, self::CLOSED_VEHICLE, true) && ($holder = self::vehicleByVin($offer->vin, $vehicle->id))) {
                throw IdentityTaken::of($holder, 'vin', 'VIN уже у ТС '.self::vehicleName($holder));
            }
        }
    }

    public static function guardVehicle(Vehicle $vehicle): void
    {
        $new = ! $vehicle->exists;
        if (($new || $vehicle->isDirty('ref_key')) && ($holder = self::vehicleByRef($vehicle->ref_key, $vehicle->id))) {
            throw IdentityTaken::of($holder, 'ref', 'Номер убытка уже у ТС '.self::vehicleName($holder));
        }
        if (! in_array($vehicle->state, self::CLOSED_VEHICLE, true) && ($new || $vehicle->isDirty(['vin', 'state'])) && ($holder = self::vehicleByVin($vehicle->vin, $vehicle->id))) {
            throw IdentityTaken::of($holder, 'vin', 'VIN уже у ТС '.self::vehicleName($holder));
        }
        // Тождество ТС зеркалит её предложение (`Sale::toOffer`): номер или VIN другого предложения — не встанет.
        if ($vehicle->offer_id && ($new || $vehicle->isDirty(['ref_key', 'vin', 'offer_id']))) {
            if ($vehicle->ref_key && ($holder = self::offerByClaim($vehicle->ref_key, $vehicle->offer_id))) {
                throw IdentityTaken::of($holder, 'ref', 'Номер убытка уже у предложения '.self::offerName($holder));
            }
            if ($holder = self::offerByVin($vehicle->vin, $vehicle->offer_id)) {
                throw IdentityTaken::of($holder, 'vin', 'VIN уже у предложения '.self::offerName($holder));
            }
        }
    }

    public static function offerName(Offer $offer): string
    {
        return trim('№'.$offer->number.' '.$offer->titleWithYear());
    }

    public static function vehicleName(Vehicle $vehicle): string
    {
        return trim($vehicle->titleWithYear().' '.($vehicle->plate ?? ''));
    }

    private static function offers(?int $except)
    {
        return Offer::withoutGlobalScopes()->where('is_demo', false)->when($except, fn ($q) => $q->whereKeyNot($except));
    }
}
