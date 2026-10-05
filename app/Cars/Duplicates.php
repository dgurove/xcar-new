<?php

namespace App\Cars;

use App\Offers\Actions\MergeOffers;
use App\Offers\Offer;
use App\Park\Actions\MergeVehicles;
use App\Park\Vehicle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Двойники, что успели завестись до замка (`Identity`, уникальные индексы): ТС и предложения с одним номером убытка
 * (любые) или одним VIN (живые). Сначала ТС — их объединение сводит и их предложения, потом предложения.
 */
final class Duplicates
{
    /** @return Collection<int, Collection<int, Vehicle>> */
    public static function vehicles(): Collection
    {
        $closed = collect(Identity::CLOSED_VEHICLE)->map->value->all();

        return self::groups(DB::table('park_vehicles')->whereNotNull('ref_key')->groupBy('ref_key')->havingRaw('count(*) > 1')->pluck('ref_key')
            ->map(fn ($k) => Vehicle::where('ref_key', $k)->orderBy('id')->get())
            ->merge(DB::table('park_vehicles')->whereRaw('length(vin) = 17')->whereNotIn('state', $closed)->groupBy('vin')->havingRaw('count(*) > 1')->pluck('vin')
                ->map(fn ($vin) => Vehicle::where('vin', $vin)->whereNotIn('state', $closed)->orderBy('id')->get())));
    }

    /** @return Collection<int, Collection<int, Offer>> */
    public static function offers(): Collection
    {
        $closed = collect(Identity::CLOSED_OFFER)->map->value->all();
        $offers = fn () => Offer::withoutGlobalScopes()->where('is_demo', false)->orderBy('id');

        return self::groups(DB::table('offers')->where('is_demo', false)->whereNotNull('claim_ref_key')->groupBy('claim_ref_key')->havingRaw('count(*) > 1')->pluck('claim_ref_key')
            ->map(fn ($k) => $offers()->where('claim_ref_key', $k)->get())
            ->merge(DB::table('offers')->where('is_demo', false)->whereRaw('length(vin) = 17')->whereNotIn('state', $closed)->groupBy('vin')->havingRaw('count(*) > 1')->pluck('vin')
                ->map(fn ($vin) => $offers()->where('vin', $vin)->whereNotIn('state', $closed)->get())));
    }

    /**
     * Всё объединить: группа за группой, заново после каждой — объединение меняет и соседние группы.
     *
     * @param  callable(string): void  $say
     */
    public static function mergeAll(callable $say): int
    {
        $n = 0;
        foreach (['vehicles', 'offers'] as $kind) {
            while ($group = self::$kind()->first()) {
                $keep = $kind === 'vehicles' ? MergeVehicles::pick($group) : MergeOffers::pick($group);
                foreach ($group->reject(fn ($m) => $m->is($keep)) as $drop) {
                    $say(self::line($kind, $keep, $drop));
                    $kind === 'vehicles' ? app(MergeVehicles::class)($keep->refresh(), $drop->refresh()) : app(MergeOffers::class)($keep->refresh(), $drop->refresh());
                    $n++;
                }
            }
        }

        return $n;
    }

    public static function line(string $kind, $keep, $drop): string
    {
        return $kind === 'vehicles'
            ? "ТС #{$drop->id} ({$drop->ref}, {$drop->vin}, {$drop->state->value}) → #{$keep->id} ({$keep->state->value})"
            : "предложение №{$drop->number} #{$drop->id} ({$drop->claim_ref}, {$drop->vin}, {$drop->state->value}) → №{$keep->number} #{$keep->id} ({$keep->state->value})";
    }

    /** Группы, связанные общей записью, — одна группа (номер у одной пары, VIN у другой). */
    private static function groups(Collection $groups): Collection
    {
        $merged = [];
        foreach ($groups as $group) {
            $ids = $group->modelKeys();
            foreach ($merged as $i => $other) {
                if (array_intersect($ids, $other->modelKeys())) {
                    $group = $other->merge($group)->unique('id')->values();
                    unset($merged[$i]);
                }
            }
            $merged[] = $group;
        }

        return collect(array_values($merged));
    }
}
