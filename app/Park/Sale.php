<?php

namespace App\Park;

use App\Offers\Offer;
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
        'color' => 'color', 'mileage' => 'mileage', 'vendor_id' => 'vendor_id'];

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

    /** ТС → предложение. */
    public static function toOffer(Vehicle $vehicle, ?Offer $offer = null): void
    {
        $offer ??= $vehicle->offer_id ? Offer::withoutGlobalScopes()->find($vehicle->offer_id) : null;
        if (! $offer) {
            return;
        }
        foreach (self::MAP as $from => $to) {
            if (filled($vehicle->{$from})) {
                $offer->{$to} = $vehicle->{$from};
            }
        }
        if ($offer->isDirty()) {
            $offer->save();
        }
    }

    /** Предложение → ТС: правка тождества в CRM (`UpdateOffer`). */
    public static function toVehicle(Offer $offer, array $changed, User $by): void
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
