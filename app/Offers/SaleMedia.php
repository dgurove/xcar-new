<?php

namespace App\Offers;

use App\Park\PhotoStage;
use App\Park\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Кадры и документы предложения — `Offer::media()`. Машина одна: у предложения, связанного с ТС парковки, файлы лежат
 * у ТС, а предложение их видит (кадры от страховой и приёма и все документы; погрузка и выдача в продажу не идут) вместе
 * со своими, если такие остались. Новый файл предложения (`addMedia`: редактор, архив, письма) сохраняется у ТС —
 * второй копии нет, парковка видит его в «Фото от страховой». Без ТС — обычная связь spatie.
 *
 * @extends MorphMany<Media, Offer>
 */
final class SaleMedia extends MorphMany
{
    /** Стадии кадров ТС, что идут в продажу. */
    public const STAGES = [PhotoStage::Vendor->value, PhotoStage::Intake->value];

    public function addConstraints()
    {
        if (self::$constraints) {
            $this->scope($this->getRelationQuery(), [$this->getParentKey()]);
        }
    }

    public function addEagerConstraints(array $models)
    {
        $this->scope($this->getRelationQuery(), $this->getKeys($models, $this->localKey));
    }

    /** Кадры ТС ключуются по её предложению — один запрос на пачку, и только если кадры ТС пришли. */
    protected function buildDictionary(Collection $results)
    {
        $vehicle = Vehicle::class;
        $ids = $results->where('model_type', $vehicle)->pluck('model_id')->unique();
        $offerOf = $ids->isEmpty() ? collect() : Vehicle::whereIn('id', $ids)->pluck('offer_id', 'id');
        $dictionary = [];
        foreach ($results as $media) {
            $key = $media->model_type === $vehicle ? $offerOf[$media->model_id] ?? null : $media->model_id;
            if ($key !== null) {
                $dictionary[$key][] = $media;
            }
        }

        return $dictionary;
    }

    /** Файл предложения с ТС ложится к ТС: стадия «от страховой», источник — CRM (при выдаче не стирается). */
    public function save(Model $model)
    {
        $vehicle = $this->parent->parkVehicle;
        if (! $vehicle) {
            return parent::save($model);
        }
        if ($model->collection_name === 'photos' && ! $model->getCustomProperty('stage')) {
            $model->setCustomProperty('stage', PhotoStage::Vendor->value);
        }
        if (! $model->getCustomProperty('source')) {
            $model->setCustomProperty('source', 'crm');
        }

        return $vehicle->media()->save($model);
    }

    private function scope(Builder $query, array $ids): void
    {
        $query->where(fn (Builder $q) => $q
            ->where(fn (Builder $own) => $own->where('model_type', Offer::class)->whereIn('model_id', $ids))
            ->orWhere(fn (Builder $car) => $car->where('model_type', Vehicle::class)
                ->whereIn('model_id', Vehicle::query()->whereIn('offer_id', $ids)->select('id'))
                ->where(fn (Builder $s) => $s->where('collection_name', '!=', 'photos')
                    ->orWhereNull('custom_properties->stage')->orWhereIn('custom_properties->stage', self::STAGES))));
    }
}
