<?php

namespace App\Logistics;

use App\Cars\Region;
use App\Cars\Settlement;
use App\Offers\Offer;
use App\Park\Vehicle;
use App\Users\Role;
use App\Users\User;
use Illuminate\Support\Collection;

/**
 * Списки формы перевозки: регионы, партнёры, водители, прежние перевозчики; что подставить по машине. Списки — раз на
 * запрос (`once`, Octane сбрасывает между запросами): форма стоит и в «Без цены», и в редакторе, и в карточке менеджера.
 */
final class Choices
{
    /** @return array<int, string> */
    public static function regions(): array
    {
        // По алфавиту без оглядки на сортировку базы (en_US ставила Москву и Севастополь впереди).
        return once(fn () => Region::pluck('name', 'id')->map(fn ($n) => trim($n))->sortBy(fn ($n) => str_replace('ё', 'е', mb_strtolower($n)))->all());
    }

    /** Партнёры — менеджеры с регионами; в списке имя и регионы, свой регион проверяет `SaveTransport`. */
    public static function partners(): array
    {
        return once(function () {
            $names = Region::get(['id', 'short', 'name'])->mapWithKeys(fn (Region $r) => [$r->id => $r->short ?: $r->name]);

            return User::withRole(Role::Manager)->whereNotNull('partner_regions')->orderBy('name')->get()
                ->filter->isPartner()
                ->mapWithKeys(fn (User $u) => [$u->id => $u->name.' ('.collect($u->partner_regions)->map(fn ($id) => $names[$id] ?? null)->filter()->implode(', ').')'])
                ->all();
        });
    }

    /** @return array<int, string> */
    public static function drivers(): array
    {
        return once(fn () => User::withRole(Role::Driver)->orderBy('name')->pluck('name', 'id')->all());
    }

    /** Перевозчики прошлых перевозок — подсказкой в поле. */
    public static function carriers(): Collection
    {
        return once(fn () => Transport::whereNotNull('carrier')->distinct()->orderBy('carrier')->pluck('carrier'));
    }

    /** Регион, где машина сейчас: город предложения или адрес осмотра; у ТС парковки — её город. */
    public static function regionOf(Offer|Vehicle|null $car): ?int
    {
        if ($car instanceof Offer) {
            return $car->settlement?->region_id ?? Region::inText($car->inspection_address)?->id;
        }

        return $car?->city_id ? Settlement::find($car->city_id)?->region_id : null;
    }

    /** Откуда по умолчанию: адрес осмотра предложения, у ТС парковки — её парковка. */
    public static function fromAddress(Offer|Vehicle|null $car): ?string
    {
        return $car instanceof Offer ? $car->inspection_address : $car?->yard?->fullAddress();
    }
}
