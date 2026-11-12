<?php

namespace App\Offers;

use App\Users\Role;
use App\Users\User;
use App\Users\UserGroup;
use App\Vendors\Vendor;
use Illuminate\Support\Facades\DB;

/**
 * Кому показывать — строки «кто → когда»: [{type: group|user|super|rest, id, delay}, …]. delay — минуты от публикации
 * (0 — сразу), null — не показывать. Строка `rest` («Остальные») одна и последняя. Кто когда видит: своя строка
 * менеджера важнее его групп, группа важнее «Остальных»; в нескольких группах — самое раннее время, но «не показывать»
 * у любой из них сильнее (12.11.2026, владелец: «всем, кроме» — исключённый не видит, в какой бы ещё группе ни был).
 * Окно выбора одно (`x-audience.editor`): «Всем | Всем, кроме | Выбранным» — это те же строки: «кроме» — строки с null,
 * «выбранным» — у «Остальных» null. Те же правила у публикации машины в сделке для других (`deal_audience_rules`,
 * `Actions\PublishDeal`) и волны вендора по умолчанию.
 * Супер-покупатели (10.10.2026, владелец: «пусть все супер-покупатели будут как один менеджер») — один менеджер на
 * всех: своя строка `super`, в группе — галкой `user_groups.with_super`, «Остальные» и «Все менеджеры» — и они тоже.
 */
final class AudienceRules
{
    public const SUPER = 'super';

    public const SUPER_NAME = 'Супер-покупатели';

    /**
     * Время у строки: минуты => подпись. null — «Не показывать». Дни (от суток) выбор рисует группой «Через несколько
     * дней»: 1–5 (11.10.2026, начальнику не хватало 3 и 5 дней).
     */
    public const DELAYS = [0 => 'Сразу', 30 => 'Через 30 минут', 60 => 'Через 1 час', 120 => 'Через 2 часа', 180 => 'Через 3 часа',
        360 => 'Через 6 часов', 720 => 'Через 12 часов', 1440 => 'Через 1 день', 2880 => 'Через 2 дня', 4320 => 'Через 3 дня',
        5760 => 'Через 4 дня', 7200 => 'Через 5 дней', 10080 => 'Через 7 дней'];

    /** С какой задержки выбор — «Через несколько дней». */
    public const DAY = 1440;

    public static function everyone(): array
    {
        return [['type' => 'rest', 'id' => null, 'delay' => 0]];
    }

    /** Свои правила предложения; нет своих — волны вендора; нет и их — всем сразу. */
    public static function of(Offer $offer): array
    {
        return self::normalize($offer->audience_rules ?: ($offer->vendor?->audience_rules ?: self::everyone()));
    }

    /** Публикация машины в сделке для других менеджеров (`PublishDeal`): свои правила или всем сразу. */
    public static function forDeal(Offer $offer): array
    {
        return self::normalize($offer->deal_audience_rules ?: self::everyone());
    }

    /** Правила «всем сразу» — хранить их незачем: пусто и есть всем сразу (или как у вендора). */
    public static function isEveryone(array $rules): bool
    {
        return self::normalize($rules) === self::everyone();
    }

    /**
     * Что хранить у предложения из формы: пусто или так же, как у вендора (у него нет — всем сразу), — null, «как у вендора».
     * Прежде пустое поле нетронутой шторки писало явное «всем сразу» поверх волн вендора (`normalize('')` не пустой).
     */
    public static function own(mixed $raw, ?Vendor $vendor): ?array
    {
        $raw = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($raw) || $raw === []) {
            return null;
        }
        $rules = self::normalize($raw);

        return $rules === self::normalize($vendor?->audience_rules ?: self::everyone()) ? null : $rules;
    }

    /** «Выбранным», а никто не выбран (или все выбранные — «не видят»): такие правила не показывают никому. */
    public static function nobody(array $rules): bool
    {
        return collect(self::normalize($rules))->every(fn ($r) => $r['delay'] === null);
    }

    /** Из формы (JSON-строка или массив) — чистые строки без повторов, «Остальные» одна и в конце. */
    public static function normalize(mixed $raw): array
    {
        $raw = is_string($raw) ? json_decode($raw, true) : $raw;
        $rows = [];
        $rest = ['type' => 'rest', 'id' => null, 'delay' => 0];
        foreach (is_array($raw) ? $raw : [] as $r) {
            if (! is_array($r) || ! in_array($r['type'] ?? null, ['group', 'user', self::SUPER, 'rest'], true)) {
                continue;
            }
            $delay = ($r['delay'] ?? null) === null || $r['delay'] === '' ? null : max(0, (int) $r['delay']);
            if ($r['type'] === 'rest') {
                $rest['delay'] = $delay;

                continue;
            }
            if ($r['type'] === self::SUPER) {
                $rows[self::SUPER] ??= ['type' => self::SUPER, 'id' => 0, 'delay' => $delay];

                continue;
            }
            $key = $r['type'].':'.(int) ($r['id'] ?? 0);
            if (($r['id'] ?? 0) && ! isset($rows[$key])) {
                $rows[$key] = ['type' => $r['type'], 'id' => (int) $r['id'], 'delay' => $delay];
            }
        }

        return [...array_values($rows), $rest];
    }

    /**
     * Кто бывает в волнах — один раз на пересчёт (`SyncViewers::all` проходит все открытые предложения, `$people`
     * передаётся по ссылке и заполняется при первом предложении): менеджеры, супер-покупатели, а состав групп — когда
     * правила их называют. Без предложений ничего не читается: старые миграции зовут пересчёт до таблиц групп.
     *
     * @param  array{managers?: list<int>, super?: list<int>, members?: array<int, list<int>>, withSuper?: list<int>}|null  $people
     * @return array<int, int> id менеджера или супер-покупателя => через сколько минут после публикации он видит
     */
    public static function openings(array $rules, ?array &$people = null): array
    {
        $rules = collect(self::normalize($rules ?: self::everyone()));
        $people ??= [];
        // Демо-менеджеры тоже видят (кабинет для показа), в списках выбора их нет.
        $people['managers'] ??= User::withoutGlobalScope('demo')->withRole(Role::Manager)->pluck('id')->all();
        $people['super'] ??= User::withRole(Role::SuperBuyer)->pluck('id')->all();
        if ($rules->contains('type', 'group') && ! isset($people['members'])) {
            $groups = UserGroup::where('kind', UserGroup::MANAGERS)->get(['id', 'with_super']);
            $people['members'] = DB::table('user_group_user')->whereIn('group_id', $groups->pluck('id'))->get()
                ->groupBy('user_id')->map(fn ($r) => $r->pluck('group_id')->all())->all();
            $people['withSuper'] = $groups->where('with_super', true)->pluck('id')->all();
        }
        $groupDelay = $rules->where('type', 'group')->pluck('delay', 'id');
        $own = $rules->where('type', 'user')->pluck('delay', 'id');
        $rest = $rules->firstWhere('type', 'rest')['delay'];
        // Своя строка важнее групп, группа — «Остальных»; в нескольких группах — самое раннее время.
        $delayOf = function (bool $hasOwn, ?int $ownDelay, array $groups) use ($groupDelay, $rest): ?int {
            if ($hasOwn) {
                return $ownDelay;
            }
            $in = array_values(array_filter($groups, fn ($g) => $groupDelay->has($g)));
            if ($in) {
                $delays = array_map(fn ($g) => $groupDelay[$g], $in);

                // «Не показывать» у одной из его групп сильнее времени у другой: исключили — не видит.
                return in_array(null, $delays, true) ? null : min($delays);
            }

            return $rest;
        };

        $open = [];
        foreach ($people['managers'] as $id) {
            $delay = $delayOf($own->has($id), $own[$id] ?? null, $people['members'][$id] ?? []);
            if ($delay !== null) {
                $open[$id] = $delay;
            }
        }
        // Супер-покупатели — один менеджер на всех: время одно, закрыли — закрыто всем.
        $super = $rules->firstWhere('type', self::SUPER);
        $delay = $delayOf($super !== null, $super['delay'] ?? null, $people['withSuper'] ?? []);
        if ($delay !== null) {
            foreach ($people['super'] as $id) {
                $open[$id] ??= $delay;
            }
        }

        return $open;
    }

    /**
     * Сводка одной строкой словами окна: «Всем сразу», «Всем, кроме Роман К., Регионы», «Только Москва, Роман К.»,
     * волны — «Свои сразу, остальные через 2 дня»; «Никому». Близнец — `audience_controller.js` `summary`.
     */
    public static function summary(array $rules): string
    {
        $rules = self::normalize($rules ?: self::everyone());
        $rest = array_pop($rules);
        $groups = UserGroup::whereIn('id', collect($rules)->where('type', 'group')->pluck('id'))->pluck('name', 'id');
        $users = User::whereIn('id', collect($rules)->where('type', 'user')->pluck('id'))->get()->keyBy('id');
        $name = fn (array $r) => match ($r['type']) {
            'group' => $groups[$r['id']] ?? null,
            'user' => $users[$r['id']]?->shortName(),
            self::SUPER => self::SUPER_NAME,
        };
        $rows = collect($rules)->map(fn ($r) => $r + ['name' => $name($r)])->filter(fn ($r) => $r['name'] !== null);
        [$shown, $hidden] = $rows->partition(fn ($r) => $r['delay'] !== null);
        $words = fn ($list) => $list->pluck('name')->implode(', ');
        $timed = fn ($list) => $list->map(fn ($r) => $r['name'].' '.self::when($r['delay']))->implode(', ');

        if ($rest['delay'] === null) {
            if ($shown->isEmpty()) {
                return 'Никому';
            }

            return 'Только '.($shown->every(fn ($r) => $r['delay'] === 0) ? $words($shown) : $timed($shown));
        }
        $except = $hidden->isNotEmpty() ? ', кроме '.$words($hidden) : '';
        if ($shown->isEmpty()) {
            return 'Всем'.($rest['delay'] === 0 && $except ? '' : ' '.self::when($rest['delay'])).$except;
        }
        $text = $timed($shown).', остальные '.self::when($rest['delay']).$except;

        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    /**
     * Кому открылась волна с этим сдвигом от выхода — именами строк правил («остальные», «Москва», «Роман Корабельников»);
     * не нашлась — пусто (07.11.2026, строка истории `OfferEventType::Wave`).
     */
    public static function waveNames(array $rules, int $delay): string
    {
        $rules = collect(self::normalize($rules ?: self::everyone()))->filter(fn ($r) => $r['delay'] !== null && abs($r['delay'] - $delay) <= 1);
        $groups = UserGroup::whereIn('id', $rules->where('type', 'group')->pluck('id'))->pluck('name', 'id');
        $users = User::whereIn('id', $rules->where('type', 'user')->pluck('id'))->get()->keyBy('id');

        return $rules->map(fn ($r) => match ($r['type']) {
            'group' => $groups[$r['id']] ?? null,
            'user' => $users[$r['id']]?->shortName(),
            self::SUPER => self::SUPER_NAME,
            'rest' => 'остальные менеджеры',
        })->filter()->implode(', ');
    }

    /**
     * Для окна «Кому»: группы менеджеров (с числом людей), менеджеры (короткими именами), супер-покупатели строкой и варианты
     * времени. `people` — те же менеджеры моделями для списка выбора с аватарами.
     */
    public static function options(): array
    {
        return [
            'groups' => self::groups()->map(fn ($g) => ['id' => $g->id, 'name' => $g->name, 'count' => (int) $g->members_count])->values()->all(),
            'managers' => self::people()->map(fn ($u) => ['id' => $u->id, 'name' => $u->shortName()])->values()->all(),
            'super' => self::SUPER_NAME,
            'delays' => collect(self::DELAYS)->map(fn ($label, $min) => [$min, $label])->values()->all(),
        ];
    }

    /** Группы менеджеров с числом людей — один запрос на страницу, сколько бы окон «Кому» на ней ни было. */
    public static function groups(): \Illuminate\Support\Collection
    {
        return once(fn () => UserGroup::ofKind(UserGroup::MANAGERS)->withCount('members')->get(['id', 'name']));
    }

    /**
     * Имена строк правил, которых нет в выборе (человек уже не менеджер, группа другого вида): окно пишет их так же, как
     * `summary`, а не теряет — иначе сводка в окне расходилась бы с сохранённой.
     *
     * @return array<string, string> «user:5» → имя
     */
    public static function names(array $rules): array
    {
        $rules = collect(self::normalize($rules));
        $users = $rules->where('type', 'user')->pluck('id')->diff(self::people()->pluck('id'));
        $groups = $rules->where('type', 'group')->pluck('id')->diff(self::groups()->pluck('id'));

        return [
            ...($users->isEmpty() ? [] : User::whereIn('id', $users)->get()->mapWithKeys(fn ($u) => ['user:'.$u->id => $u->shortName()])->all()),
            ...($groups->isEmpty() ? [] : UserGroup::whereIn('id', $groups)->pluck('name', 'id')->mapWithKeys(fn ($n, $id) => ['group:'.$id => $n])->all()),
        ];
    }

    /** Менеджеры для выбора по имени — один запрос на страницу, сколько бы окон «Кому» на ней ни было. */
    public static function people(): \Illuminate\Support\Collection
    {
        return once(fn () => User::withRole(Role::Manager)->orderBy('name')->get());
    }

    public static function when(?int $delay): string
    {
        return $delay === null ? 'не показывать' : mb_strtolower(self::DELAYS[$delay] ?? 'через '.$delay.' минут');
    }
}
