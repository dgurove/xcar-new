<?php

namespace App\Offers;

use App\Cars\Brand;
use App\Cars\CarModel;
use App\Park\VehicleState;
use App\Support\Facets\Facet;
use App\Support\Facets\Option;

/**
 * Параметры шторки «Фильтр» вкладок колеса «Предложений» — одна дверь на пять видов (17.11.2026, владелец: «более навороченный
 * фильтр, как на xcar, по многим параметрам»): машина (марка, модель, год, пробег), деньги (закупочная, цена продажи — админу),
 * срок обязывающего вперёд, сколько во вкладке, «На парковке», «Без VIN». `$id` — номер предложения выражением строки списка:
 * `offers.id` у предложений, `deals.offer_id` у сделок, `garage_cars.offer_id` у гаража; у предложений поля берутся прямо.
 */
final class OfferFilters
{
    /** @return list<Facet> */
    public static function for(string $id, bool $admin, ?string $since = null): array
    {
        return [
            Facet::column('brand', 'Марка', ['марка', 'марки', 'марок'], self::car($id, 'brand_id'))->numeric()->icon('award')
                ->labels(fn (array $ids) => Brand::whereIn('id', array_map('intval', $ids))->get(['id', 'name'])
                    ->mapWithKeys(fn (Brand $b) => [(string) $b->id => new Option((string) $b->id, $b->name)])->sortBy('label')->all()),
            // Модель — из марок, что остались при прочих чипах: выбрали марку — в шторке только её модели.
            Facet::column('model', 'Модель', ['модель', 'модели', 'моделей'], self::car($id, 'model_id'))->numeric()->icon('car-front')
                ->labels(fn (array $ids) => CarModel::whereIn('id', array_map('intval', $ids))->with('brand:id,name')->get(['id', 'brand_id', 'name'])
                    ->mapWithKeys(fn (CarModel $m) => [(string) $m->id => new Option((string) $m->id, $m->name, hint: $m->brand?->name)])->all()),
            Facet::range('year', 'Год', self::car($id, 'year'))->icon('calendar'),
            Facet::range('mileage', 'Пробег', self::car($id, 'mileage'), 'км')->icon('gauge'),
            ...($admin ? [
                Facet::range('floor', 'Закупочная', self::offer($id, 'floor_price'))->money()->icon('cart'),
                Facet::range('asking', 'Цена продажи', self::offer($id, 'asking_price'))->money()->icon('tag'),
            ] : []),
            // Только пока срок что-то значит (`Binding::mattersSql`): у проданной давний срок — не «Прошёл».
            Facet::dates('until', 'Срок обязывающего', $id === 'offers.id'
                ? '(case when '.Binding::mattersSql().' then offers.binding_until end)'
                : '(select case when '.Binding::mattersSql('o').' then o.binding_until end from offers o where o.id = '.$id.')')->ahead()->icon('timer'),
            Facet::range('days', 'Во вкладке', 'floor(extract(epoch from now() - ('.($since ?? 'coalesce('.self::offer($id, 'tab_at').', '.self::offer($id, 'created_at').')').')) / 86400)', 'д')->icon('hourglass'),
            // На парковке — ТС той же машины ждёт приёма или стоит (`Offer::onPark`).
            Facet::toggle('park', 'На парковке', fn ($q) => $q->whereRaw('exists (select 1 from park_vehicles pv where pv.car_id = '.self::offer($id, 'car_id')
                ." and pv.state in ('".VehicleState::Expected->value."', '".VehicleState::Stored->value."'))"))->icon('parking'),
            Facet::toggle('novin', 'Без VIN', fn ($q) => $q->whereRaw('coalesce('.self::car($id, 'vin').", '') = ''"))->icon('barcode'),
        ];
    }

    /** Поле предложения: у списка предложений — прямо, у сделок и гаража — подзапросом по номеру. */
    public static function offer(string $id, string $column): string
    {
        return $id === 'offers.id' ? "offers.{$column}" : "(select o.{$column} from offers o where o.id = {$id})";
    }

    /** Поле машины предложения (`Cars\Car`). */
    public static function car(string $id, string $column): string
    {
        return $id === 'offers.id' ? \App\Cars\Car::sql('offers', $column) : "(select c.{$column} from offers o join cars c on c.id = o.car_id where o.id = {$id})";
    }
}
