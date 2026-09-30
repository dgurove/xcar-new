<?php

namespace App\Offers;

use App\Users\ManagerGroup;
use App\Users\Role;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/**
 * Кому показывать — строки «кто → когда»: [{type: group|user|rest, id, delay}, …]. delay — минуты от публикации
 * (0 — сразу), null — не показывать. Строка `rest` («Остальные») одна и последняя. Кто когда видит: своя строка
 * менеджера важнее его групп, группа важнее «Остальных»; в нескольких группах — самое раннее время.
 */
final class AudienceRules
{
    /** Время у строки: минуты => подпись. null — «Не показывать». */
    public const DELAYS = [0 => 'Сразу', 30 => 'Через 30 минут', 60 => 'Через 1 час', 120 => 'Через 2 часа', 180 => 'Через 3 часа',
        360 => 'Через 6 часов', 720 => 'Через 12 часов', 1440 => 'Через сутки', 2880 => 'Через 2 суток'];

    public static function everyone(): array
    {
        return [['type' => 'rest', 'id' => null, 'delay' => 0]];
    }

    /** Свои правила предложения; нет своих — шаблон вендора по умолчанию; нет и его — всем сразу. */
    public static function of(Offer $offer): array
    {
        return self::normalize($offer->audience_rules ?: ($offer->vendor?->audience?->rules ?: self::everyone()));
    }

    /** Из формы (JSON-строка или массив) — чистые строки без повторов, «Остальные» одна и в конце. */
    public static function normalize(mixed $raw): array
    {
        $raw = is_string($raw) ? json_decode($raw, true) : $raw;
        $rows = [];
        $rest = ['type' => 'rest', 'id' => null, 'delay' => 0];
        foreach (is_array($raw) ? $raw : [] as $r) {
            if (! is_array($r) || ! in_array($r['type'] ?? null, ['group', 'user', 'rest'], true)) {
                continue;
            }
            $delay = ($r['delay'] ?? null) === null || $r['delay'] === '' ? null : max(0, (int) $r['delay']);
            if ($r['type'] === 'rest') {
                $rest['delay'] = $delay;

                continue;
            }
            $key = $r['type'].':'.(int) ($r['id'] ?? 0);
            if (($r['id'] ?? 0) && ! isset($rows[$key])) {
                $rows[$key] = ['type' => $r['type'], 'id' => (int) $r['id'], 'delay' => $delay];
            }
        }

        return [...array_values($rows), $rest];
    }

    /** @return array<int, int> id менеджера => через сколько минут после публикации он видит */
    public static function openings(array $rules): array
    {
        $rules = self::normalize($rules ?: self::everyone());
        // Демо-менеджеры тоже видят (кабинет для показа), в списках выбора их нет.
        $managers = User::withoutGlobalScope('demo')->where('role', Role::Manager)->pluck('id')->all();
        $groups = collect($rules)->where('type', 'group');
        $members = $groups->isNotEmpty()
            ? DB::table('manager_group_user')->whereIn('group_id', $groups->pluck('id'))->get()->groupBy('user_id')->map(fn ($r) => $r->pluck('group_id')->all())
            : collect();
        $groupDelay = $groups->pluck('delay', 'id');
        $own = collect($rules)->where('type', 'user')->pluck('delay', 'id');
        $rest = collect($rules)->firstWhere('type', 'rest')['delay'];

        $open = [];
        foreach ($managers as $id) {
            if ($own->has($id)) {
                $delay = $own[$id];
            } elseif ($in = $members[$id] ?? null) {
                $delays = array_filter(array_map(fn ($g) => $groupDelay[$g], $in), fn ($d) => $d !== null);
                $delay = $delays ? min($delays) : null;
            } else {
                $delay = $rest;
            }
            if ($delay !== null) {
                $open[$id] = $delay;
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
        $groups = ManagerGroup::whereIn('id', collect($rules)->where('type', 'group')->pluck('id'))->pluck('name', 'id');
        $users = User::whereIn('id', collect($rules)->where('type', 'user')->pluck('id'))->get()->keyBy('id');
        $parts = [];
        foreach ($rules as $r) {
            [$name, $many] = match ($r['type']) {
                'group' => [$groups[$r['id']] ?? null, true],
                'user' => [$users[$r['id']]?->shortName(), false],
                'rest' => ['остальные', true],
            };
            if ($name) {
                $parts[] = $name.' '.($r['delay'] === null ? ($many ? 'не видят' : 'не видит') : self::when($r['delay']));
            }
        }
        $text = implode(', ', $parts);

        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }

    /** Для шторки «Кому»: группы, менеджеры (короткими именами), шаблоны со строками и варианты времени. */
    public static function options(bool $presets = true): array
    {
        return [
            'groups' => ManagerGroup::orderBy('position')->orderBy('name')->get(['id', 'name'])->map(fn ($g) => ['id' => $g->id, 'name' => $g->name])->all(),
            'managers' => User::where('role', Role::Manager)->orderBy('name')->get()->map(fn ($u) => ['id' => $u->id, 'name' => $u->shortName()])->all(),
            'presets' => $presets ? Audience::orderBy('position')->orderBy('name')->get()->map(fn ($a) => ['id' => $a->id, 'name' => $a->name, 'rules' => self::normalize($a->rules)])->all() : [],
            'delays' => collect(self::DELAYS)->map(fn ($label, $min) => [$min, $label])->values()->all(),
        ];
    }

    public static function when(?int $delay): string
    {
        return $delay === null ? 'не показывать' : mb_strtolower(self::DELAYS[$delay] ?? 'через '.$delay.' минут');
    }
}
