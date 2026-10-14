<?php

namespace App\Cars;

use App\Cars\Vin\Vin;
use App\Offers\Offer;
use App\Offers\OfferState;
use App\Park\Vehicle;
use App\Park\VehicleState;
use Illuminate\Database\Eloquent\Builder;

/**
 * Одна машина — одна запись (владелец 05.10.2026; с 14.10.2026 — одна запись `Car` на всё приложение). Номер убытка —
 * одно событие: двух машин с ним нет никогда, тот же номер поднимает прежнюю. VIN — машина: среди живых (`Car::active`)
 * он один, а закрытая (продана, выдана, архив, отмена) новой не мешает — машина вернулась после нового ДТП. Продажа и
 * хранение у машины по одной: новая дорожка той же машины встаёт на неё (`OnCar`, `JoinCars::settle`), вторая продажа —
 * ошибка поля. Сторож — в `saving` машины, в базе — уникальные индексы.
 */
final class Identity
{
    public const CLOSED_OFFER = [OfferState::Delivered, OfferState::Cancelled, OfferState::Archived];

    public const CLOSED_VEHICLE = [VehicleState::Released, VehicleState::Cancelled];

    /** Машина с этим номером (любая) или, если по номеру нет, с этим VIN (живая). */
    public static function carFor(?string $claimKey, ?string $vin, ?int $except = null): ?Car
    {
        return self::carByClaim($claimKey, $except) ?? self::carByVin($vin, $except);
    }

    public static function carByClaim(?string $claimKey, ?int $except = null): ?Car
    {
        return $claimKey ? self::cars($except)->where('claim_ref_key', $claimKey)->first() : null;
    }

    public static function carByVin(?string $vin, ?int $except = null): ?Car
    {
        $vin = Vin::full($vin);

        return $vin ? self::cars($except)->where('vin', $vin)->where('active', true)->first() : null;
    }

    /**
     * Та же машина, что уже есть: номер убытка (любая), иначе VIN среди живых, если номера не спорят (у одной пусто или
     * тот же). Новая запись и правка номера или VIN встают на неё (`JoinCars::settle`).
     */
    public static function holderFor(Car $car): ?Car
    {
        if ($car->is_demo) {
            return null;
        }
        $except = $car->exists ? $car->id : null;
        if ($car->claim_ref_key && (! $car->exists || $car->isDirty('claim_ref_key')) && ($holder = self::carByClaim($car->claim_ref_key, $except))) {
            return $holder;
        }
        if ($car->vin && (! $car->exists || $car->isDirty('vin')) && ($holder = self::carByVin($car->vin, $except))) {
            return ! $holder->claim_ref_key || ! $car->claim_ref_key || $holder->claim_ref_key === $car->claim_ref_key ? $holder : null;
        }

        return null;
    }

    /** Перед записью машины: номер или VIN у другой — `IdentityTaken` с её дорожкой. */
    public static function guardCar(Car $car): void
    {
        if ($car->is_demo) {
            return;
        }
        $except = $car->exists ? $car->id : null;
        if ((! $car->exists || $car->isDirty('claim_ref_key')) && ($holder = self::carByClaim($car->claim_ref_key, $except))) {
            throw IdentityTaken::of(self::face($holder), 'claim_ref', self::refWord($holder).' уже у '.self::carName($holder));
        }
        // VIN проверяется и когда машина оживает (вернули из архива): рядом с живым двойником её не поднять.
        if ($car->active && (! $car->exists || $car->isDirty(['vin', 'active'])) && ($holder = self::carByVin($car->vin, $except))) {
            throw IdentityTaken::of(self::face($holder), 'vin', 'VIN уже у '.self::carName($holder));
        }
    }

    /**
     * Живое предложение другой машины с этим VIN — VIN в это предложение не пишем (Мигторг, «✨», «Из текста»): это
     * чужая продажа. Машина без продажи (только парковка) — та же машина, предложение на неё встанет.
     */
    public static function offerByVin(?string $vin, ?int $exceptOffer = null): ?Offer
    {
        $car = self::carByVin($vin, $exceptOffer ? Offer::withoutGlobalScopes()->whereKey($exceptOffer)->value('car_id') : null);
        $offer = $car?->offer;

        return $offer && ! in_array($offer->state, self::CLOSED_OFFER, true) ? $offer : null;
    }

    /** Предложение той же машины (по номеру — любое, по VIN — живой машины). */
    public static function offerFor(?string $claimKey, ?string $vin): ?Offer
    {
        return self::carFor($claimKey, $vin)?->offer;
    }

    /** ТС парковки той же машины. */
    public static function vehicleFor(?string $claimKey, ?string $vin): ?Vehicle
    {
        return self::carFor($claimKey, $vin)?->vehicle;
    }

    public static function offerName(Offer $offer): string
    {
        return trim('№'.$offer->number.' '.$offer->titleWithYear());
    }

    public static function vehicleName(Vehicle $vehicle): string
    {
        return trim($vehicle->titleWithYear().' '.($vehicle->plate ?? ''));
    }

    /** «№1234 Kia Rio, 2019», иначе «ТС Kia Rio, 2019 А123ВС77», иначе «лот закупки ДЛ …». */
    public static function carName(Car $car): string
    {
        return match (true) {
            (bool) ($offer = $car->offer) => self::offerName($offer),
            (bool) ($vehicle = $car->vehicle) => 'ТС '.self::vehicleName($vehicle),
            default => trim('лот закупки '.($car->claim_ref ?? '').' '.$car->titleWithYear()),
        };
    }

    /** Кого показать «уже у …»: продажу, иначе хранение, иначе саму машину. */
    public static function face(Car $car): Offer|Vehicle|Car
    {
        return $car->offer ?? $car->vehicle ?? $car;
    }

    private static function refWord(Car $car): string
    {
        return $car->offer?->leaseRef() ? 'Номер ДЛ' : 'Номер убытка';
    }

    private static function cars(?int $except): Builder
    {
        return Car::query()->where('is_demo', false)->when($except, fn ($q) => $q->whereKeyNot($except));
    }
}
