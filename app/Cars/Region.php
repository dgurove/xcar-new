<?php

namespace App\Cars;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Регион России: номер — как на номерах машин (50 — Московская область), ключ ОКТМО (две цифры, автономный округ
 * внутри области — пять), имя полное и короткое («Моск. обл.»), `match` — как его пишут в адресах (регулярка без
 * границ). Данные — database/data/regions.csv, грузит `SettlementImport`.
 */
#[Fillable(['oktmo', 'plate', 'name', 'short', 'match'])]
class Region extends Model
{
    public $timestamps = false;

    /** @var array<int, self>|null */
    private static ?array $all = null;

    /** Первый регион, названный в тексте адреса: «Пермский край, д. Ванюки», «Ярославская обл, р-н …». */
    public static function inText(?string $text): ?self
    {
        $text = mb_strtolower(trim((string) $text));
        if ($text === '') {
            return null;
        }
        self::$all ??= self::all()->all();
        $best = null;
        foreach (self::$all as $region) {
            if (preg_match('/'.$region->match.'/u', $text, $m, PREG_OFFSET_CAPTURE) && ($best === null || $m[0][1] < $best[1])) {
                $best = [$region, $m[0][1]];
            }
        }

        return $best[0] ?? null;
    }
}
