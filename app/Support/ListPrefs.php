<?php

namespace App\Support;

use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Память списка: вид, сортировка и «по сколько», как их оставили. Явный параметр в адресе
 * важнее памяти и запоминается; без параметра — подставляется запомненное,
 * так что таб и «‹ Раздел» ведут на чистый адрес, а список открывается как
 * его оставили. У пользователя — users.list_prefs, у гостя — cookie на год.
 */
final class ListPrefs
{
    private const KEYS = ['sort', ListView::PARAM, ListView::PER];

    public static function sync(Request $request, string $list, bool $rememberTable = false): void
    {
        // Таблица не запоминается: сама она включается только у длинного списка (ListView::pick),
        // а запомненная включалась бы и на трёх строках; вид в адресе — на этот раз.
        // rememberTable — у списков, где таблица и есть вид по умолчанию (почта): выбор «строками» и обратно помнится.
        $table = fn ($v, $k) => ! $rememberTable && $k === ListView::PARAM && ListView::isTable($v);
        $saved = array_filter(self::all($request)[$list] ?? [], fn ($v, $k) => ! $table($v, $k), ARRAY_FILTER_USE_BOTH);
        $given = array_filter($request->only(self::KEYS), fn ($v, $k) => is_string($v) && $v !== '' && ! $table($v, $k), ARRAY_FILTER_USE_BOTH);
        // Пришли по адресу с параметрами — это выбор, запоминаем.
        if ($given && $given !== array_intersect_key($saved, $given)) {
            self::remember($request, $list, $given + $saved);
        }
        foreach ($saved as $key => $value) {
            if (! $request->query->has($key) && ! $request->query->has('page')) {
                $request->query->set($key, $value);
            }
        }
    }

    private static function all(Request $request): array
    {
        $user = $request->user();
        if ($user) {
            return $user->list_prefs ?? [];
        }
        $raw = json_decode((string) $request->cookie('list_prefs'), true);

        return is_array($raw) ? $raw : [];
    }

    private static function remember(Request $request, string $list, array $prefs): void
    {
        $all = self::all($request);
        $all[$list] = $prefs;
        if ($user = $request->user()) {
            User::whereKey($user->id)->update(['list_prefs' => json_encode($all)]);
            $user->list_prefs = $all;
        } else {
            Cookie::queue('list_prefs', json_encode($all), 60 * 24 * 365);
        }
    }
}
