<?php

namespace App\Cars\Vin;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Известен ли WMI — первые три знака VIN. Справочник (`resources/vin/wmi.json`, Wikibooks) не знает российских
 * сборочных площадок последних лет: «EDE…» у Chery и Tenet, «EDA…» у Voyah и Evolute. Поэтому к нему добавляются
 * WMI из нашей базы (`vin_facts` — машины с маркой, заведённые людьми или пришедшие от Carcade): два VIN с одним WMI —
 * значит, такой производитель есть. Список базы — в кеше на сутки и в памяти процесса на пять минут (под Octane
 * проверка VIN не ходит в базу на каждый знак); новый WMI сбрасывает кеш (`RememberVin`).
 */
final class KnownWmi
{
    private const KEY = 'vin:wmi:known';

    private static ?array $table = null;

    private static ?array $learned = null;

    private static int $at = 0;

    public static function has(string $vin): bool
    {
        $wmi = strtoupper(substr($vin, 0, 3));

        return isset(self::table()[$wmi]) || isset(self::learned()[$wmi]);
    }

    /** WMI, которые знает только наша база: два VIN и больше. @return array<string, true> */
    public static function learned(): array
    {
        if (self::$learned === null || time() - self::$at > 300) {
            self::$learned = Cache::remember(self::KEY, 86400, fn () => DB::table('vin_facts')
                ->selectRaw('upper(substr(prefix, 1, 3)) as wmi')->groupByRaw('upper(substr(prefix, 1, 3))')->havingRaw('count(*) >= 2')
                ->pluck('wmi')->mapWithKeys(fn ($w) => [$w => true])->all());
            self::$at = time();
        }

        return self::$learned;
    }

    /** VIN с WMI, которого ещё нет ни в справочнике, ни в выученных, — список пересчитать. */
    public static function saw(string $vin): void
    {
        if (! self::has($vin)) {
            Cache::forget(self::KEY);
            self::$learned = null;
        }
    }

    private static function table(): array
    {
        return self::$table ??= (new VinTables)->wmi();
    }
}
