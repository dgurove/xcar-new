<?php

namespace App\Cars\Actions;

use App\Cars\Car;
use App\Cars\Identity;
use App\Cars\IdentityTaken;
use App\Offers\Actions\MergeOffers;
use App\Offers\Offer;
use App\Park\Actions\MergeVehicles;
use App\Park\EventType;
use App\Park\Vehicle;
use App\Users\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Две записи одной машины — в одну (14.10.2026). Так бывает, когда о машине узнали дважды: письмо в CRM и заявка
 * парковки, лот закупки и предложение, номер убытка вписали позже. Остаётся `$into`: к ней переходят дорожки (две
 * продажи или два хранения сливаются — `MergeOffers::lanes`, `MergeVehicles::lanes`), файлы (повтор по отпечатку `sha`
 * не переносится) и пустые поля; `$from` удаляется. Номер убытка у оставшейся — свой, у неё пусто — от второй.
 */
final class JoinCars
{
    /**
     * Машина дорожки при записи (`OnCar::save`): новая или с новым номером и VIN может оказаться той, что уже есть
     * (`Identity::holderFor`) — дорожка встаёт на неё. Что правили сейчас — у найденной так и будет, пустое у найденной —
     * из нашей. Своей дорожки того же вида у найденной быть не должно: две продажи одной машины — ошибка поля «уже у
     * №…», как и раньше. Прежняя машина записанной дорожки сливается в найденную целиком после записи.
     */
    public static function settle(Model $lane, Car $car): Car
    {
        $holder = Identity::holderFor($car);
        if (! $holder) {
            return $car;
        }
        $field = $car->claim_ref_key && $car->claim_ref_key === $holder->claim_ref_key ? 'claim_ref' : 'vin';
        $kinds = ['offer' => Offer::class, 'vehicle' => Vehicle::class];
        foreach ($kinds as $kind => $class) {
            $twin = $holder->{$kind};
            $ours = $lane instanceof $class ? $lane : ($car->exists ? $car->{$kind} : null);
            if ($twin && $ours && ! ($ours->exists && $twin->is($ours))) {
                $name = $twin instanceof Offer ? Identity::offerName($twin) : 'ТС '.Identity::vehicleName($twin);
                throw IdentityTaken::of($twin, $field, ($field === 'vin' ? 'VIN' : ($twin instanceof Offer && $twin->leaseRef() ? 'Номер ДЛ' : 'Номер убытка')).' уже у '.$name);
            }
        }
        // Кто прав, когда записи сходятся: тождество машины держит парковка (её данные — из писем страховой и приёма), как
        // раньше при связи ТС с предложением. ТС встаёт на машину — её поля и правят; продажа или лот встают на машину с ТС —
        // только дополняют пустое; без ТС правка записанной дорожки — что вписали сейчас, то и у машины.
        $override = $lane instanceof Vehicle || ($car->exists && ! $holder->vehicle()->exists());
        foreach (Car::FIELDS as $f) {
            $value = $car->getAttribute($f);
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $empty = in_array($holder->getAttribute($f), [null, '', []], true);
            if ($empty || ($override && $car->isDirty($f))) {
                $holder->setAttribute($f, $value);
            }
        }
        // Продажа встала на машину с ТС (или ТС — на машину с продажей): в истории ТС «Связана с предложением № …».
        $paired = ($lane instanceof Offer && $holder->vehicle()->exists()) || ($lane instanceof Vehicle && $holder->offer()->exists());
        $lane->joinCarAfterSave($car->exists ? $car->id : null, $paired);

        return $holder;
    }

    /** Продажа и хранение машины встретились — запись в истории ТС. */
    public static function paired(Car $car, ?User $by = null): void
    {
        if (($vehicle = $car->vehicle()->first()) && ($offer = $car->offer()->first())) {
            $vehicle->log(EventType::Linked, $by ?? auth()->user(), ['number' => $offer->number]);
        }
    }

    /**
     * Слить `$from` в `$into`. Две продажи — остаётся `$keepOffer` (иначе `MergeOffers::pick`), два хранения —
     * `$keepVehicle` (иначе `MergeVehicles::pick`).
     *
     * @return array{photos: int, dropped: int}
     */
    public function __invoke(Car $from, Car $into, ?User $by = null, ?Offer $keepOffer = null, ?Vehicle $keepVehicle = null): array
    {
        if ($from->is($into)) {
            return ['photos' => 0, 'dropped' => 0];
        }

        return DB::transaction(function () use ($from, $into, $by, $keepOffer, $keepVehicle) {
            // Тождество держит парковка: ТС только у второй — её поля главнее (как прежде при связи ТС с предложением).
            $parkFirst = $from->vehicle()->exists() && ! $into->vehicle()->exists();
            // Продажа и хранение были разными записями — теперь одна машина: в истории ТС «Связана с предложением № …».
            $linked = ($from->offer()->exists() && $into->vehicle()->exists()) || ($from->vehicle()->exists() && $into->offer()->exists());
            $media = $this->media($from, $into);
            // Две продажи (два хранения) одной машины — в одну: остаётся выбранная, вторая отдаёт ей всё и уходит.
            if (($a = $into->offer()->first()) && ($b = $from->offer()->first())) {
                $keep = $keepOffer && in_array($keepOffer->id, [$a->id, $b->id], true) ? $keepOffer : MergeOffers::pick(collect([$a, $b]));
                MergeOffers::lanes($keep, $keep->is($a) ? $b : $a, $by);
            }
            if (($a = $into->vehicle()->first()) && ($b = $from->vehicle()->first())) {
                $keep = $keepVehicle && in_array($keepVehicle->id, [$a->id, $b->id], true) ? $keepVehicle : MergeVehicles::pick(collect([$a, $b]));
                MergeVehicles::lanes($keep, $keep->is($a) ? $b : $a, $by);
            }
            foreach (['offers', 'park_vehicles', 'purchase_cars', 'transports'] as $table) {
                DB::table($table)->where('car_id', $from->id)->update(['car_id' => $into->id]);
            }
            $from = $from->fresh() ?? $from;
            $fill = [];
            foreach (Car::FIELDS as $f) {
                $value = $from->getAttribute($f);
                if (! in_array($value, [null, '', []], true) && ($parkFirst || in_array($into->getAttribute($f), [null, '', []], true))) {
                    $fill[$f] = $value;
                }
            }
            // Номер и VIN второй встанут только после её удаления: до того они ещё её (уникальные индексы).
            Car::whereKey($from->id)->delete();
            $into->fill($fill)->save();
            $into->refreshActive();
            if ($linked) {
                self::paired($into, $by);
            }

            return $media;
        });
    }

    /** Файлы второй — к оставшейся; что уже есть по отпечатку, уходит вместе со второй. */
    private function media(Car $from, Car $into): array
    {
        $known = Media::where('model_type', Car::class)->where('model_id', $into->id)->get()
            ->flatMap(fn (Media $m) => array_filter([$m->getCustomProperty('sha'), $m->getCustomProperty('sent_sha')]))->flip();
        $moved = $dropped = 0;
        foreach (Media::where('model_type', Car::class)->where('model_id', $from->id)->get() as $media) {
            $sha = $media->getCustomProperty('sha');
            if ($sha && $known->has($sha)) {
                $media->delete();
                $dropped++;

                continue;
            }
            $media->forceFill(['model_id' => $into->id])->save();
            $sha && $known->put($sha, true);
            $moved++;
        }

        return ['photos' => $moved, 'dropped' => $dropped];
    }
}
