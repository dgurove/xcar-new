<?php

namespace App\Offers;

use App\Users\Role;
use App\Users\User;
use App\Users\UserGroup;
use Illuminate\Support\Facades\DB;

/**
 * Кому показывать — строки «кто → когда»: [{type: group|user|super|rest, id, delay}, …]. delay — минуты от публикации
 * (0 — сразу), null — не показывать. Строка `rest` («Остальные») одна и последняя. Кто когда видит: своя строка
 * менеджера важнее его групп, группа важнее «Остальных»; в нескольких группах — самое раннее время.
 * Супер-покупатели (10.10.2026, владелец: «пусть все супер-покупатели будут как один менеджер») — один менеджер на
 * всех: своя строка `super`, в группе — галкой `user_groups.with_super`, «Остальные» и «Все менеджеры» — и они тоже.
 */
final class AudienceRules
{
    public const SUPER = 'super';

    public const SUPER_NAME = 'Супер-покупатели';

    /** Время у строки: минуты => подпись. null — «Не показывать». */
    public const DELAYS = [0 => 'Сразу', 30 => 'Через 30 минут', 60 => 'Через 1 час', 120 => 'Через 2 часа', 180 => 'Через 3 часа',
        360 => 'Через 6 часов', 720 => 'Через 12 часов', 1440 => 'Через сутки', 2880 => 'Через 2 суток'];

    public static function everyone(): array
    {
        return [['type' => 'rest', 'id' => null, 'delay' => 0]];
    }

    /** Свои правила предложения; нет своих — волны вендора; нет и их — всем сразу. */
    public static function of(Offer $offer): array
    {
        return self::normalize($offer->audience_rules ?: ($offer->vendor?->audience_rules ?: self::everyone()));
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
                $delays = array_filter(array_map(fn ($g) => $groupDelay[$g], $in), fn ($d) => $d !== null);

                return $delays ? min($delays) : null;
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

    /** Сводка одной строкой: «Свои сразу, Москва через 2 часа, остальные через сутки». */
    public static function summary(array $rules): string
    {
        $rules = self::normalize($rules ?: self::everyone());
        $rest = end($rules);
        if (count($rules) === 1) {
            return $rest['delay'] === null ? 'Никому' : 'Всем '.self::when($rest['delay']);
        }
        $groups = UserGroup::whereIn('id', collect($rules)->where('type', 'group')->pluck('id'))->pluck('name', 'id');
        $users = User::whereIn('id', collect($rules)->where('type', 'user')->pluck('id'))->get()->keyBy('id');
        $parts = [];
        foreach ($rules as $r) {
            [$name, $many] = match ($r['type']) {
                'group' => [$groups[$r['id']] ?? null, true],
                'user' => [$users[$r['id']]?->shortName(), false],
                self::SUPER => [self::SUPER_NAME, true],
                'rest' => ['остальные', true],
            };
            if ($name) {
                $parts[] = $name.' '.($r['delay'] === null ? ($many ? 'не видят' : 'не видит') : self::when($r['delay']));
            }
        }
        $text = implode(', ', $parts);

        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    /** Для шторки «Кому»: группы менеджеров, менеджеры (короткими именами), супер-покупатели строкой и варианты времени. */
    public static function options(): array
    {
        return [
            'groups' => UserGroup::ofKind(UserGroup::MANAGERS)->get(['id', 'name'])->map(fn ($g) => ['id' => $g->id, 'name' => $g->name])->all(),
            'managers' => User::withRole(Role::Manager)->orderBy('name')->get()->map(fn ($u) => ['id' => $u->id, 'name' => $u->shortName()])->all(),
            'super' => self::SUPER_NAME,
            'delays' => collect(self::DELAYS)->map(fn ($label, $min) => [$min, $label])->values()->all(),
        ];
    }

    public static function when(?int $delay): string
    {
        return $delay === null ? 'не показывать' : mb_strtolower(self::DELAYS[$delay] ?? 'через '.$delay.' минут');
    }
}
