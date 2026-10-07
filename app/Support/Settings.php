<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Переключатели площадки из CRM (`app_settings`, ключ → JSON). Читаются раз за запрос: память — атрибут запроса, а не
 * статика (воркер Octane живёт между запросами, и статика держала бы вчерашнее значение).
 */
final class Settings
{
    private const MEMO = 'app_settings';

    public static function get(string $key, mixed $default = null): mixed
    {
        // В консоли (очередь, опрос бота) процесс живёт часами — там без памяти, каждый раз из базы.
        $request = ! app()->runningInConsole() && app()->bound('request') ? request() : null;
        $all = $request?->attributes->get(self::MEMO);
        if (! is_array($all)) {
            $all = DB::table('app_settings')->pluck('value', 'key')->map(fn ($v) => json_decode($v, true))->all();
            $request?->attributes->set(self::MEMO, $all);
        }

        return $all[$key] ?? $default;
    }

    public static function put(string $key, mixed $value, ?int $by = null): void
    {
        DB::table('app_settings')->updateOrInsert(['key' => $key], ['value' => json_encode($value), 'updated_by' => $by, 'updated_at' => now()]);
        app()->bound('request') && request()->attributes->remove(self::MEMO);
    }
}
