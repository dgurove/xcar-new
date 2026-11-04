<?php

namespace App\Workflow;

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
     * @return Collection<int, array{stage: ?Stage, name: string, state: string, at: ?Carbon, user_id: ?int}>
     */
    public static function for(Position $position, Collection $journal, ?Deal $deal = null): Collection
    {
        $stage = $position->stage;
        $block = $stage->block;
        $stages = $block->stages()->with('exits.to')->get();
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
                'state' => self::DONE, 'at' => $next['at'] ?? null, 'user_id' => $next['user_id'] ?? null]);
        }

        $rows->push(['stage' => $stage, 'name' => $stage->name, 'state' => self::NOW, 'at' => $position->entered_at, 'user_id' => null]);

        $current = $byId->get($stage->id) ?? $stage;
        while (true) {
            $targets = $current->exits->filter(fn (Outcome $e) => $e->actor !== Actor::Timer && $e->fits($deal))
                ->map(fn (Outcome $e) => $e->to_stage_id)->filter(fn ($id) => $byId->has($id))->unique()->values();
            if ($targets->count() !== 1 || in_array($targets->first(), $seen, true)) {
                break;
            }
            $current = $byId->get($targets->first());
            $seen[] = $current->id;
            $rows->push(['stage' => $current, 'name' => $current->name, 'state' => self::WAIT, 'at' => null, 'user_id' => null]);
        }

        return $rows;
    }
}
