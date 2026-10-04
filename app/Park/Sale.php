<?php

namespace App\Park;

use App\Offers\Offer;
use App\Offers\OfferState;
use App\Park\Actions\LinkOffer;
use App\Park\Actions\UpdateVehicle;
use App\Users\User;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * ТС парковки в продаже — одна машина. Файлы лежат у ТС, предложение их видит (`Offers\SaleMedia`). Тождество — у ТС,
 * предложение его зеркалит: колонки у предложения остаются (по ним фильтры, каталог, поиск), но пишет их ТС; правка
 * в редакторе CRM уходит в ТС, оттуда — обратно; пустое у ТС непустое у предложения не стирает. Вендора правит только
 * парковка: по нему считается хранение. Всё зовёт `Vehicle::saved` при смене связи и полей — у какой двери ни свяжи.
 */
final class Sale
{
    /** Поле ТС => поле предложения. */
    public const MAP = ['ref' => 'claim_ref', 'vin' => 'vin', 'brand_id' => 'brand_id', 'model_id' => 'model_id', 'year' => 'year',
        'color' => 'color', 'mileage' => 'mileage', 'vendor_id' => 'vendor_id', 'value' => 'value'];

    /** Связь сменилась: файлы прежнего предложения, принесённые им, — назад; файлы нового — к ТС, без копий. */
    public static function relinked(Vehicle $vehicle, ?int $was): void
    {
        if ($was) {
            Media::where('model_type', Vehicle::class)->where('model_id', $vehicle->id)->where('custom_properties->offer', (string) $was)
                ->update(['model_type' => Offer::class, 'model_id' => $was]);
        }
        if ($vehicle->offer_id) {
            self::bring($vehicle);
        }
    }

    /**
     * Предложение, заведённое в CRM руками или из писем, — той же машины, что стоит на парковке (номер убытка или VIN, ровно
     * одна живая ТС без предложения): связать. Связь сама переносит файлы предложения к ТС (`relinked`).
     */
    public static function adopt(Offer $offer): void
    {
        if ($offer->is_demo || in_array($offer->state, [OfferState::Archived, OfferState::Cancelled], true) || Vehicle::where('offer_id', $offer->id)->exists()) {
            return;
        }
        $vehicle = LinkOffer::guessVehicle($offer);
        if ($vehicle && ! $vehicle->state->isFinal()) {
            app(LinkOffer::class)($vehicle, $offer);
        }
    }

    /** Файл парковки, а не продажи (снят при приёме, пришёл письмом её ящиков): CRM его прячет, но не удаляет. */
    public static function parkOwned(Media $media): bool
    {
        return $media->model_type === Vehicle::class && $media->getCustomProperty('source') !== 'crm' && ! $media->getCustomProperty('offer');
    }

    /** ТС стирают — предложению возвращаются его файлы и всё, что добавили в CRM. */
    public static function release(Vehicle $vehicle): void
    {
        Media::where('model_type', Vehicle::class)->where('model_id', $vehicle->id)
            ->where(fn ($q) => $q->whereNotNull('custom_properties->offer')->orWhere('custom_properties->source', 'crm'))
            ->update(['model_type' => Offer::class, 'model_id' => $vehicle->offer_id]);
    }

    /**
     * Файлы предложения — к ТС: стадия «от страховой», метка `offer` (при отвязке уедут обратно). Путь файла у spatie —
     * по id кадра, файлы на диске не двигаются.
     */
    public static function bring(Vehicle $vehicle): int
    {
        $moved = Media::where('model_type', Offer::class)->where('model_id', $vehicle->offer_id)->get();
        foreach ($moved as $media) {
            if ($media->collection_name === 'photos' && ! $media->getCustomProperty('stage')) {
                $media->setCustomProperty('stage', PhotoStage::Vendor->value);
            }
            $media->setCustomProperty('offer', (string) $vehicle->offer_id);
            $media->forceFill(['model_type' => Vehicle::class, 'model_id' => $vehicle->id])->save();
        }

        return $moved->count();
    }

    /**
     * Закупочная из оценочной стоимости (владелец, 04.10.2026): до 500 000 — плюс 50 000, от 500 000 до 2 000 000 — плюс
     * 10 %, от 2 000 000 — плюс 200 000; вверх до тысячи.
     */
    public static function floorFrom(int $value): int
    {
        $price = match (true) {
            $value < 500_000 => $value + 50_000,
            $value < 2_000_000 => $value * 1.1,
            default => $value + 200_000,
        };

        return (int) (ceil(round($price, 2) / 1000) * 1000);
    }

    /**
     * Закупочная после смены оценочной: пустая или посчитанная от прежней оценочной — заново от новой; вписанная рукой
     * остаётся. null — трогать не нужно.
     */
    public static function floorFor(?int $floor, ?int $was, ?int $value): ?int
    {
        if (! $value || $value === $was) {
            return null;
        }
        $auto = $floor === null || ($was && $floor === self::floorFrom($was));

        return $auto ? self::floorFrom($value) : null;
    }

    /** ТС → предложение. */
    public static function toOffer(Vehicle $vehicle, ?Offer $offer = null): void
    {
        $offer ??= $vehicle->offer_id ? Offer::withoutGlobalScopes()->find($vehicle->offer_id) : null;
        if (! $offer) {
            return;
        }
        $was = $offer->value;
        foreach (self::MAP as $from => $to) {
            if (filled($vehicle->{$from})) {
                $offer->{$to} = $vehicle->{$from};
            }
        }
        // Закупочная — из оценочной, пока её не вписали (владелец, 04.10.2026); вписанную не трогаем.
        if ($floor = self::floorFor($offer->floor_price, $was, $vehicle->value ? (int) $vehicle->value : null) ?? ($offer->floor_price === null && $vehicle->value ? self::floorFrom((int) $vehicle->value) : null)) {
            $offer->floor_price = $floor;
        }
        if ($offer->isDirty()) {
            $offer->save();
        }
    }

    /** Предложение → ТС: правка тождества в CRM (`UpdateOffer`). */
    public static function toVehicle(Offer $offer, array $changed, ?User $by): void
    {
        $vehicle = $offer->parkVehicle;
        $fields = array_intersect_key(array_flip(array_diff_key(self::MAP, ['vendor_id' => 1])), array_flip($changed));
        if (! $vehicle || ! $fields) {
            return;
        }
        $data = [];
        foreach ($fields as $to => $from) {
            $data[$from] = $offer->{$to};
        }
        app(UpdateVehicle::class)($vehicle, $data, $by);
    }
}
