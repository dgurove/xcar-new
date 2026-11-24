<?php

namespace App\Workflow;

use App\Support\ShowMemo;
use App\Offers\Deal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Подпункты текущего шага пути — этапы его блока на пути этой сделки (04.11.2026, владелец: «как переоценку — так и
 * остальные этапы»; блок «Согласование с поставщиком» говорил одно имя, а внутри — письмо поставщику, согласие менеджера,
 * подтверждение покупки). Каждый `done` | `now` | `wait`:
 * - **done** — пройденные в блоке по журналу (`Path::journal`) с последнего входа в него: подпись — исход, которым ушли
 *   («Поставщик согласовал», «Беру в работу»), кто и когда;
 * - **now** — текущий этап;
 * - **wait** — дальше внутри блока, пока путь однозначен: исходы, подходящие сделке (`Outcome::fits`), ведут в один этап
 *   того же блока; развилка ряд обрывает.
 *
 * Блок с одним подпунктом подпунктов не рисует (`x-route.path`): шаг и есть он.
 */
final class Substeps
{
    public const DONE = 'done';

    public const NOW = 'now';

    public const WAIT = 'wait';

    /**
     * @param  Collection  $journal  `Path::journal` той же ветки
     * @return Collection<int, array{stage: ?Stage, name: string, state: string, at: ?Carbon, user_id: ?int, what: list<string>, for: ?string}>
     */
    public static function for(Position $position, Collection $journal, ?Deal $deal = null): Collection
    {
        $stage = $position->stage;
        $block = $stage->block;
        $stages = ShowMemo::get('block-stages:'.$block->id, fn () => $block->stages()->with('exits.to')->get());
        $byId = $stages->keyBy('id');

        // Хвост журнала в этом блоке: с последнего входа в блок, без самого текущего этапа.
        $tail = [];
        foreach ($journal->reverse()->values() as $entry) {
            if ($entry['block'] !== $block->name) {
                break;
            }
            array_unshift($tail, $entry);
        }
        $last = end($tail);
        $entered = $last && ($last['stage_id'] ? $last['stage_id'] === $stage->id : $last['stage'] === $stage->name) ? array_pop($tail) : null;

        $rows = collect();
        $seen = [$stage->id];
        foreach ($tail as $i => $entry) {
            // Ушли исходом следующей записи: она же — когда и кто.
            $next = $tail[$i + 1] ?? $entered;
            $done = $byId->get($entry['stage_id']) ?? $stages->firstWhere('name', $entry['stage']);
            $seen[] = $done?->id;
            $rows->push(['stage' => $done, 'name' => $next['exit'] ?? $done?->name ?? (string) $entry['stage'],
                'state' => self::DONE, 'at' => $next['at'] ?? null, 'user_id' => $next['user_id'] ?? null,
                'what' => $next ? self::what($next, $done, $deal) : [], 'for' => $next['for'] ?? null]);
        }

        $rows->push(['stage' => $stage, 'name' => $stage->name, 'state' => self::NOW, 'at' => $position->entered_at, 'user_id' => null, 'what' => [], 'for' => null]);

        $current = $byId->get($stage->id) ?? $stage;
        while (true) {
            $targets = $current->exits->filter(fn (Outcome $e) => $e->actor !== Actor::Timer && $e->fits($deal))
                ->map(fn (Outcome $e) => $e->to_stage_id)->filter(fn ($id) => $byId->has($id))->unique()->values();
            if ($targets->count() !== 1 || in_array($targets->first(), $seen, true)) {
                break;
            }
            $current = $byId->get($targets->first());
            $seen[] = $current->id;
            $rows->push(['stage' => $current, 'name' => $current->name, 'state' => self::WAIT, 'at' => null, 'user_id' => null, 'what' => [], 'for' => null]);
        }

        return $rows;
    }

    /**
     * Что решили выходом (`ExitWhat`, в событии с 24.11.2026). Прежние записи без значения: время подписания — по сделке сейчас,
     * пока она в этом блоке («Запись на подписание»), колонки ещё те самые. @return list<string>
     */
    public static function what(array $entry, ?Stage $from, ?Deal $deal): array
    {
        if (! empty($entry['what'])) {
            return array_values((array) $entry['what']);
        }
        $exit = $deal && $from ? $from->exits->first(fn (Outcome $e) => $e->label === ($entry['raw_exit'] ?? $entry['exit'])) : null;
        if (! $exit || ! (\App\Offers\Signing::pickExit($exit) || \App\Offers\Signing::confirmExit($exit) || \App\Offers\Signing::slotsExit($exit))) {
            return [];
        }
        // Время, что согласовали, и слоты поставщика потом перезаписывает «Запись подтверждена»: текущее значение — их, только пока
        // сделка стоит там, куда этот выход привёл. Подтверждённое время после записи не меняется (новые слоты сматывают журнал).
        if (! \App\Offers\Signing::confirmExit($exit) && ($entry['stage_id'] ?? null) !== $deal->offer?->position()?->stage_id) {
            return [];
        }

        return ExitWhat::signing($deal, $exit);
    }
}
