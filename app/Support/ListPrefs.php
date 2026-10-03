<?php

namespace App\Support;

use App\Users\Impersonation;
use App\Users\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Память списка: вид, сортировка, «по сколько» и чипы фильтров, как их оставили. Явный параметр в адресе
 * важнее памяти и запоминается; без параметра — подставляется запомненное,
 * так что таб и «‹ Раздел» ведут на чистый адрес, а список открывается как
 * его оставили. У пользователя — users.list_prefs, у гостя — cookie на год.
 */
final class ListPrefs
{
    private const KEYS = ['sort', ListView::PARAM, ListView::PER];

    /**
     * $keep — ключи чипов фильтра (Facets::keys): помнятся так же. Чип сняли (× и «Сбросить» ведут на `?vendor=` —
     * параметр есть, но пустой) — значение забывается и больше не подставляется. $view = false — только чипы
     * (у почты сортировка своя у каждой пилюли и не помнится).
     */
    public static function sync(Request $request, string $list, bool $rememberTable = false, array $keep = [], bool $view = true): void
    {
        $keys = [...($view ? self::KEYS : []), ...$keep];
        // Таблица не запоминается: сама она включается только у длинного списка (ListView::pick),
        // а запомненная включалась бы и на трёх строках; вид в адресе — на этот раз.
        // rememberTable — у списков, где таблица и есть вид по умолчанию (почта): выбор «строками» и обратно помнится.
        $table = fn ($v, $k) => ! $rememberTable && $k === ListView::PARAM && ListView::isTable($v);
        // Память списка целиком: ключи чипов другого вида (покупатели и сотрудники в «Пользователях») не теряются.
        $raw = self::all($request)[$list] ?? [];
        $saved = array_filter($raw, fn ($v, $k) => in_array($k, $keys, true) && ! $table($v, $k), ARRAY_FILTER_USE_BOTH);
        $given = array_filter($request->only($keys), fn ($v, $k) => is_string($v) && $v !== '' && ! $table($v, $k), ARRAY_FILTER_USE_BOTH);
        $cleared = array_values(array_filter($keep, fn ($k) => $request->query->has($k) && blank($request->query($k))));
        // Пришли по адресу с параметрами — это выбор, запоминаем. Админ за человека его память не трогает, а «сколько
        // будет» из шторки чипа (X-Count) — ещё не выбор.
        $next = array_diff_key($given + $raw, array_flip($cleared));
        // Предзагрузка при наведении (`MarkPrefetch`) — тоже не выбор: иначе «×», над которым прошёл курсор, стирал фильтр.
        if (($given || $cleared) && ! Impersonation::active() && ! $request->headers->has('X-Count') && ! $request->prefetch() && $next != $raw) {
            self::remember($request, $list, $next);
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
