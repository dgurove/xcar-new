<?php

namespace App\Park\Actions;

use App\Offers\Actions\CreateOffer;
use App\Offers\CarPlace;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Park\EventType;
use App\Park\InspectionKind;
use App\Park\PhotoStage;
use App\Park\Vehicle;
use App\Park\VehicleState;
use App\Users\User;
use App\Workflow\Actions\SetCarPlace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Принятая ТС уходит с парковки в продажу: в CRM появляется черновик предложения, его оценит и опубликует сотрудник.
 * Предложение с тем же убытком или VIN, если оно уже есть и живое, не дублируется — ТС привязывается к нему.
 * Повторный вызов безопасен (двойной клик, повтор пачки): второго предложения не будет, у черновика
 * докопируются недостающие кадры. Связь — `park_vehicles.offer_id`, одна на предложение.
 *
 * Черновик заводится через `CreateOffer`, а не `UpdateOffer`: тот при вендоре запускает маршруты, и вывоз
 * завёл бы заявку на эвакуацию машины, которая уже стоит у нас.
 */
final class SendToSale
{
    public const CREATED = 'created';

    public const LINKED = 'linked';

    public const ALREADY = 'already';

    public function __construct(private CreateOffer $create, private SetCarPlace $place) {}

    /** @return array{offer: Offer, status: string} */
    public function __invoke(Vehicle $vehicle, User $by): array
    {
        [$offer, $status] = DB::transaction(function () use ($vehicle, $by) {
            $vehicle = Vehicle::whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            if ($vehicle->offer_id && ($offer = Offer::find($vehicle->offer_id))) {
                return [$offer, self::ALREADY];
            }
            if ($vehicle->state !== VehicleState::Stored) {
                throw ValidationException::withMessages(['vehicle' => 'В продажу уходит принятая ТС']);
            }
            $found = LinkOffer::guess($vehicle);
            $found = $found && in_array($found->state, [OfferState::Draft, OfferState::Gallery, OfferState::Open], true) ? $found : null;
            $offer = $found ?? ($this->create)($by, $this->data($vehicle), ['park_vehicle' => $vehicle->id]);
            $vehicle->update(['offer_id' => $offer->id]);
            $vehicle->log(EventType::SentToSale, $by, ['number' => $offer->number, 'linked' => (bool) $found]);
            if ($found) {
                $offer->log(OfferEventType::Note, $by, ['text' => 'К предложению привязана ТС с парковки']);
            }
            // Положение двигает слушатель по событию приёма; приём был раньше, так что ставим «у нас» сами.
            ($this->place)($offer, CarPlace::Ours, $by);

            return [$offer, $found ? self::LINKED : self::CREATED];
        });
        // Кадры — после транзакции: файлы тяжёлые, а откат оставил бы их сиротами.
        if ($offer->state === OfferState::Draft) {
            $this->copyPhotos($vehicle->refresh(), $offer);
        }

        return ['offer' => $offer, 'status' => $status];
    }

    /** @return array<string, mixed> */
    private function data(Vehicle $vehicle): array
    {
        $yard = $vehicle->yard;
        $vin = $vehicle->vin ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $vehicle->vin)) : null;

        return array_filter([
            'claim_ref' => $vehicle->ref,
            'vin' => $vin,
            'brand_id' => $vehicle->brand_id,
            'model_id' => $vehicle->model_id,
            'year' => $vehicle->year,
            'color' => $vehicle->color,
            'vendor_id' => $vehicle->vendor_id,
            'mileage' => $vehicle->mileage ?? $vehicle->inspections->first(fn ($i) => $i->kind === InspectionKind::Intake)?->mileage,
            'insured_name' => $vehicle->contact_name,
            'insured_phone' => $vehicle->contact_phone,
            'flags' => $vehicle->flags,
            'docs_required' => $vehicle->docs_required,
            'floor_price' => $vehicle->value,
            'settlement_id' => $yard?->settlement_id,
            'inspection_address' => $yard?->fullAddress(),
            'prices_include_vat' => $vehicle->vendor?->offers_include_vat,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    /**
     * Кадры от страховой и при приёме; при погрузке и выдаче — про движение ТС, не про продажу. Копия самостоятельна
     * (у ТС кадры остаются), метки стадии ей ни к чему, а `from_park` не даёт лечь второй раз.
     */
    private function copyPhotos(Vehicle $vehicle, Offer $offer): void
    {
        foreach ($vehicle->photos() as $media) {
            if ($media->getCustomProperty('hidden', false) || in_array(PhotoStage::of($media), [PhotoStage::Pickup, PhotoStage::Release], true)) {
                continue;
            }
            $sha = (string) $media->getCustomProperty('sha');
            if (($sha !== '' && $offer->hasFile($sha)) || $offer->media()->where('custom_properties->from_park', $media->id)->exists()) {
                continue;
            }
            try {
                /** @var Media $copy */
                $copy = $media->copy($offer, 'photos');
                foreach (['stage', 'slot', 'source'] as $key) {
                    $copy->forgetCustomProperty($key);
                }
                $copy->setCustomProperty('from_park', $media->id)->save();
            } catch (Throwable $e) {
                // Продажа уже заведена; недостающие кадры доедут повторным вызовом.
                report($e);
            }
        }
    }
}
