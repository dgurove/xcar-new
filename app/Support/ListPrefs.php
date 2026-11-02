<?php

namespace App\Support;

use App\Users\Impersonation;
use App\Users\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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

    /** `?reset=1` — чипы списка как по умолчанию (`resetUrl`). */
    public const RESET = 'reset';

    /**
     * $keep — ключи чипов фильтра (Facets::keys): помнятся так же. Чип сняли (× и «Сбросить» ведут на `?vendor=` —
     * параметр есть, но пустой) — помнится пустым и подставляется пустым: умолчание экрана («Кроме Каркаде») после
     * этого не встаёт ни на одной вкладке (владелец 04.10.2026: «убрал фильтр, переключился — опять фильтр»). $view = false — только чипы
     * (у почты сортировка своя у каждой пилюли и не помнится). $sort = false — сортировка не помнится (каталог сайта:
     * запомненная «сначала новые» прятала пачки по сроку, 11.10.2026).
     */
    public static function sync(Request $request, string $list, array $keep = [], bool $view = true, bool $sort = true): void
    {
        $keys = [...($view ? self::KEYS : []), ...$keep];
        // Вид на сайте помнится — тот, что человек выбрал сам (16.10.2026, менеджер: «ставлю плитки, захожу — опять
        // ленты»); не выбирал — умолчание `ListView::pick`. Старые запомненные виды, из-за которых 11.10 у людей застревали
        // строки, стёрты миграцией. В CRM (владелец 04.10.2026) и на парковке (09.10.2026) вид не помнится: по умолчанию
        // таблица, другой вид — на этот раз.
        $site = Surface::current() === Surface::Site;
        $keys = array_values(array_diff($keys, [...($site ? [] : [ListView::PARAM]), ...($sort ? [] : ['sort'])]));
        // Память списка целиком: ключи чипов другого вида (покупатели и сотрудники в «Пользователях») не теряются.
        $raw = self::all($request)[$list] ?? [];
        if ($request->query(self::RESET) === '1') {
            self::forget($request, $list, $raw, $keep);
        }
        $saved = array_intersect_key($raw, array_flip($keys));
        $given = array_filter($request->only($keys), fn ($v) => is_string($v) && $v !== '');
        $cleared = array_values(array_filter($keep, fn ($k) => $request->query->has($k) && blank($request->query($k))));
        // Пришли по адресу с параметрами — это выбор, запоминаем. Админ за человека его память не трогает, а «сколько
        // будет» из шторки чипа (X-Count) — ещё не выбор.
        $next = array_merge($given + $raw, array_fill_keys($cleared, ''));
        // Предзагрузку Turbo берёт за клик (`touch-prefetch.js` отдаёт тот же ответ), поэтому её нельзя отбрасывать здесь;
        // у «×» и «Сбросить» предзагрузки нет (`data-turbo-prefetch="false"`), иначе курсор над ними стирал бы фильтр.
        if (($given || $cleared) && ! Impersonation::active() && ! $request->headers->has('X-Count') && $next != $raw) {
            self::remember($request, $list, $next);
        }
        foreach ($saved as $key => $value) {
            if (! $request->query->has($key) && ! $request->query->has('page')) {
                $request->query->set($key, $value);
            }
        }
    }

    /**
     * Сбросить чипы до умолчания (02.11.2026, владелец: «кнопку „Сбросить“, чтобы сбрасывать до тех, которые по
     * умолчанию»): × и «Сбросить» в ряду чипов запоминают пустое — «без фильтра», и умолчание экрана («Кроме Каркаде»)
     * больше не встаёт; здесь память чипов стирается, и экран открывается как в первый раз. Сортировка и «по сколько»
     * остаются. Ответ — переход на тот же адрес без `reset`.
     */
    private static function forget(Request $request, string $list, array $raw, array $keep): never
    {
        $left = array_diff_key($raw, array_flip($keep));
        if ($left != $raw && ! Impersonation::active()) {
            self::remember($request, $list, $left);
        }
        $query = Arr::except($request->query(), [self::RESET, ...$keep, Portion::PART, Portion::ROWS, 'page', 'peek']);

        throw new HttpResponseException(redirect('/'.ltrim($request->path(), '/').($query ? '?'.Arr::query($query) : '')));
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
