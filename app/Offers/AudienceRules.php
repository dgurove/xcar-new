<?php

namespace App\Offers;

use App\Users\ManagerGroup;
use App\Users\Role;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/**
 * Кому показывать — волны: [{delay, all, groups, users}, …]. delay — минуты от публикации (0 — сразу),
 * null — «не показывать»: такая волна исключает своих, даже если другие их называют. Менеджер из нескольких
 * волн видит с самой ранней; не попал ни в одну — не видит. Пустые правила — «все сразу».
 */
final class AudienceRules
{
    /** Выбор срока в строке волны: минуты => подпись. «Не показывать» — отдельной строкой (null). */
    public const DELAYS = [0 => 'Сразу', 30 => '30 мин', 60 => '1 ч', 120 => '2 ч', 180 => '3 ч', 360 => '6 ч', 720 => '12 ч', 1440 => '1 д', 2880 => '2 д'];

    public static function everyone(): array
    {
        return [['delay' => 0, 'all' => true, 'groups' => [], 'users' => []]];
    }

    /** Свои правила предложения; нет своих — шаблон вендора по умолчанию; нет и его — все сразу. */
    public static function of(Offer $offer): array
    {
        return $offer->audience_rules ?: ($offer->vendor?->audience?->rules ?: self::everyone());
    }

    /** Из формы (JSON-строка или массив) — чистые волны; пустые (без кого) выбрасываются. */
    public static function normalize(mixed $raw): array
    {
        $raw = is_string($raw) ? json_decode($raw, true) : $raw;
        $waves = [];
        foreach (is_array($raw) ? $raw : [] as $w) {
            if (! is_array($w)) {
                continue;
            }
            $delay = $w['delay'] ?? null;
            $wave = [
                'delay' => $delay === null || $delay === '' ? null : max(0, (int) $delay),
                'all' => (bool) ($w['all'] ?? false),
                'groups' => array_values(array_unique(array_map('intval', (array) ($w['groups'] ?? [])))),
                'users' => array_values(array_unique(array_map('intval', (array) ($w['users'] ?? [])))),
            ];
            if ($wave['all'] || $wave['groups'] || $wave['users']) {
                $waves[] = $wave;
            }
        }

        return $waves;
    }

    public static function isEveryoneNow(array $rules): bool
    {
        $rules = $rules ?: self::everyone();

        return collect($rules)->contains(fn ($w) => $w['all'] && $w['delay'] === 0) && ! collect($rules)->contains(fn ($w) => $w['delay'] === null);
    }

    /** @return array<int, int> id менеджера => через сколько минут после публикации он видит */
    public static function openings(array $rules): array
    {
        $rules = $rules ?: self::everyone();
        // Демо-менеджеры тоже видят (кабинет для показа), в списках выбора их нет.
        $managers = User::withoutGlobalScope('demo')->where('role', Role::Manager)->pluck('id')->all();
        $groupIds = collect($rules)->pluck('groups')->flatten()->unique()->all();
        $members = $groupIds ? DB::table('manager_group_user')->whereIn('group_id', $groupIds)->get()->groupBy('group_id')->map(fn ($r) => $r->pluck('user_id')->all()) : collect();

        $open = [];
        $never = [];
        foreach ($rules as $w) {
            $ids = $w['all'] ? $managers : array_merge($w['users'], ...array_map(fn ($g) => $members[$g] ?? [], $w['groups']));
            foreach (array_intersect(array_unique($ids), $managers) as $id) {
                if ($w['delay'] === null) {
                    $never[$id] = true;
                } else {
                    $open[$id] = min($open[$id] ?? PHP_INT_MAX, $w['delay']);
                }
            }
        }

        return array_diff_key($open, $never);
    }

    /** Сводка одной строкой для чипа: «Свои сразу, все через 2 ч, Новые не видят». */
    public static function summary(array $rules): string
    {
        $rules = $rules ?: self::everyone();
        $groups = ManagerGroup::whereIn('id', collect($rules)->pluck('groups')->flatten()->unique())->pluck('name', 'id');
        $users = User::whereIn('id', collect($rules)->pluck('users')->flatten()->unique())->get()->keyBy('id');
        $parts = [];
        foreach ($rules as $w) {
            $who = $w['all'] ? ['все'] : [...array_filter(array_map(fn ($g) => $groups[$g] ?? null, $w['groups'])), ...array_filter(array_map(fn ($u) => $users[$u]?->shortName(), $w['users']))];
            if (! $who) {
                continue;
            }
            $parts[] = implode(', ', $who).' '.self::when($w['delay']);
        }

        return $parts ? mb_strtoupper(mb_substr($parts[0], 0, 1)).mb_substr(implode(', ', $parts), 1) : 'Никому';
    }

    /** Для шторки «Кому»: группы, менеджеры (короткими именами) и шаблоны с их волнами. */
    public static function options(bool $presets = true): array
    {
        return [
            'groups' => ManagerGroup::orderBy('position')->orderBy('name')->get(['id', 'name'])->map(fn ($g) => ['id' => $g->id, 'name' => $g->name])->all(),
            'managers' => User::where('role', Role::Manager)->orderBy('name')->get()->map(fn ($u) => ['id' => $u->id, 'name' => $u->shortName()])->all(),
            'presets' => $presets ? Audience::orderBy('position')->orderBy('name')->get()->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'rules' => $a->rules, 'summary' => $a->summary()])->all() : [],
        ];
    }

    public static function when(?int $delay): string
    {
        return match (true) {
            $delay === null => 'не видят',
            $delay === 0 => 'сразу',
            $delay < 60 => "через {$delay} мин",
            $delay < 1440 => 'через '.round($delay / 60).' ч',
            default => 'через '.round($delay / 1440).' д',
        };
    }
}
