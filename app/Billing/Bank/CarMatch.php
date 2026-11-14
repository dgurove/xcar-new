<?php

namespace App\Billing\Bank;

use App\Mail\Extraction\Code;
use App\Offers\Deal;
use App\Offers\DealState;
use Illuminate\Support\Facades\DB;

/**
 * Платёж выписки → машина → её сделка (14.11.2026): по назначению платежа — VIN (17 знаков), номер убытка («по договору
 * комиссии № 238163/2026», «0790/046/09068/26», «AUT-26-…» — `Code::key` против `cars.claim_ref_key`), госномер
 * («р/н У757РУ152», кириллица и латиница одним видом). Машина должна найтись одна; сделка — последняя идущая или
 * состоявшаяся. Две машины, ни одной, машина без сделки — null: не угадываем.
 */
final class CarMatch
{
    private const LAT = 'ABEKMHOPCTYX';

    private const CYR = 'АВЕКМНОРСТУХ';

    /** @return array{deal: Deal, by: string}|null */
    public static function of(?string $purpose): ?array
    {
        $text = mb_strtoupper((string) $purpose);
        if ($text === '') {
            return null;
        }
        foreach (['vin' => self::byVin($text), 'claim' => self::byClaim($text), 'plate' => self::byPlate($text)] as $by => $cars) {
            $cars = array_values(array_unique($cars));
            if (count($cars) > 1) {
                return null;
            }
            if ($cars && ($deal = self::dealOf($cars[0]))) {
                return ['deal' => $deal, 'by' => $by];
            }
        }

        return null;
    }

    /** @return list<int> */
    private static function byVin(string $text): array
    {
        preg_match_all('/(?<![A-Z0-9])[A-HJ-NPR-Z0-9]{17}(?![A-Z0-9])/', $text, $m);
        $vins = array_values(array_filter(array_unique($m[0]), fn ($v) => preg_match('/\d/', $v) && preg_match('/[A-Z]/', $v)));

        return $vins ? DB::table('cars')->whereIn(DB::raw('upper(vin)'), $vins)->pluck('id')->all() : [];
    }

    /** @return list<int> */
    private static function byClaim(string $text): array
    {
        // Номер убытка — кусок с цифрами и «/» или «-» («238163/2026», «0790/046/09068/26», «AUT-26-979366»).
        // Пробел у дроби бывает («по договору комиссии № 201184 /2026», выписка 14.11.2026).
        preg_match_all('/(?<![\p{L}\p{N}])[\p{L}\p{N}]*\d[\p{L}\p{N}]*(?:\s?[\/\-]\s?[\p{L}\p{N}]+)+/u', $text, $m);
        $keys = array_values(array_filter(array_unique(array_map(fn ($c) => Code::key($c), $m[0])), fn ($k) => $k && mb_strlen($k) >= 6));

        return $keys ? DB::table('cars')->whereIn('claim_ref_key', $keys)->pluck('id')->all() : [];
    }

    /** @return list<int> */
    private static function byPlate(string $text): array
    {
        $letters = self::LAT.self::CYR;
        preg_match_all('/(?<![\p{L}\p{N}])['.$letters.']\s?\d{3}\s?['.$letters.']{2}\s?\d{2,3}(?![\p{L}\p{N}])/u', $text, $m);
        $plates = array_values(array_unique(array_map(fn ($p) => self::latin(preg_replace('/\s+/u', '', $p)), $m[0])));
        if (! $plates) {
            return [];
        }

        return DB::table('cars')->whereNotNull('plate')
            ->whereIn(DB::raw("translate(upper(plate), '".self::CYR."', '".self::LAT."')"), $plates)->pluck('id')->all();
    }

    private static function latin(string $s): string
    {
        return strtr($s, array_combine(mb_str_split(self::CYR), str_split(self::LAT)));
    }

    /** Последняя идущая или состоявшаяся сделка машины. */
    private static function dealOf(int $carId): ?Deal
    {
        return Deal::whereIn('state', [DealState::Active, DealState::Done])
            ->whereHas('offer', fn ($o) => $o->where('car_id', $carId))->latest('id')->first();
    }
}
