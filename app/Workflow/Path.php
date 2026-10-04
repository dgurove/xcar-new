<?php

namespace App\Workflow;

use App\Offers\Deal;
use App\Offers\Destination;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Путь предложения по ветке маршрута, как трекинг заказа: пройденные блоки по журналу (`StageEntered`), текущий,
 * впереди — пока путь однозначен. Одно место на редактор CRM, сделку и кабинет менеджера («Путь сделки»).
 */
final class Path
{
    public const DONE = 'done';

    public const CURRENT = 'current';

    public const NEXT = 'next';

    /**
     * @param  ?Carbon  $since  журнал с этой даты (кабинет менеджера — с начала сделки)
     * @return Collection<int, array{block: Block, state: string, at: ?Carbon, stages: Collection}>
     */
    public static function for(Offer $offer, Track $track, ?Carbon $since = null): Collection
    {
        $position = $offer->position($track);
        if (! $position) {
            return collect();
        }
        $journal = self::journal($offer, $track, $since);
        $blocks = self::ladder($position->stage, $journal->pluck('block')->all(), $offer->branchFor($track));
        $passed = true;

        return $blocks->map(function (Block $block) use (&$passed, $position, $journal) {
            $current = $block->id === $position->stage->block_id;
            // Конечный этап (выходов нет — «Сделка закрыта») не ждёт действия: он пройден, галочкой, а не текущим.
            $final = $current && $position->stage->exits->isEmpty();
            $state = $final ? self::DONE : ($current ? self::CURRENT : ($passed ? self::DONE : self::NEXT));
            if ($current) {
                $passed = false;
            }
            $stages = $journal->where('block', $block->name)->values();

            return [
                'block' => $block,
                'state' => $state,
                // Когда в блок вошли: у текущего — по позиции, у пройденного — первый вход по журналу.
                'at' => $current ? $position->block_entered_at : ($stages->first()['at'] ?? null),
                'stages' => $stages,
            ];
        });
    }

    /**
     * Этапы, в которые входило предложение, по порядку: блок, этап, выход, которым пришли, откуда, дата. Вход в
     * этап, где уже были, сматывает журнал до прежнего входа: отменённые и пройденные зря шаги из пути пропадают
     * (в «Истории» они остаются), и вернувшийся назад блок не стоит галочкой впереди текущего.
     *
     * @return Collection<int, array{at: Carbon, block: string, stage: ?string, stage_id: ?int, from: ?string, from_id: ?int, exit: ?string}>
     */
    public static function journal(Offer $offer, Track $track, ?Carbon $since = null): Collection
    {
        $entries = $offer->events()->where('type', OfferEventType::StageEntered)->when($since, fn ($q) => $q->where('created_at', '>=', $since))->reorder()->orderBy('id')->get()
            ->filter(fn ($e) => ($e->payload['track'] ?? Track::Sale->value) === $track->value && ($e->payload['block'] ?? null));
        $journal = [];
        foreach ($entries as $e) {
            $p = $e->payload;
            $entry = ['at' => $e->created_at, 'block' => $p['block'], 'stage' => $p['to'] ?? null, 'stage_id' => $p['to_id'] ?? null,
                'from' => $p['from'] ?? null, 'from_id' => $p['from_id'] ?? null, 'exit' => $p['exit'] ?? null];
            // Снова в этапе, где уже были (откат, «Оплата не поступила», «Отказываюсь»), — всё после прежнего входа
            // из пути уходит. Откат оставляет прежний вход (с ним и выход, которым пришли тогда, — его и отменит
            // следующий откат), обычный возврат — новый: отменять надо уже его («Оплата не поступила»).
            for ($i = count($journal) - 1; $i >= 0; $i--) {
                $same = $entry['stage_id'] && $journal[$i]['stage_id'] ? $journal[$i]['stage_id'] === $entry['stage_id'] : $journal[$i]['stage'] === $entry['stage'];
                if ($same) {
                    array_splice($journal, empty($p['back']) ? $i : $i + 1);
                    if (! empty($p['back'])) {
                        continue 2;
                    }
                    break;
                }
            }
            $journal[] = $entry;
        }

        return collect($journal);
    }

    /**
     * Лестница: позади — где предложение было по журналу, текущий блок, впереди — пока путь однозначен. Развилка её
     * обрывает: у маршрута с двумя ветками покупки была бы видна и та, от которой отказались. Тупики срыва и возвраты
     * назад впереди не считаются. Блоки сравниваются по имени: у двух веток покупки блок «Согласуем с поставщиком» свой.
     *
     * $guess — менеджеру (04.10.2026, владелец: путь из одного шага ничего не говорит): на развилке путь идёт дальше
     * главной дорогой — двойник текущего блока (ветка «на себя» с тем же именем) не в счёт, из остальных — раньше
     * стоящий в маршруте. Пройденное всё равно по журналу; CRM рисует путь «серым до развилки», без догадки.
     *
     * @return Collection<int, Block>
     */
    public static function ladder(Stage $stage, array $passedNames, Deal|Destination|null $deal = null, bool $guess = false): Collection
    {
        $all = $stage->workflow->blocks()->with('stages.exits.to.block')->get();
        $current = $all->firstWhere('id', $stage->block_id);
        $behind = collect($passedNames)->map(fn ($name) => $all->firstWhere('name', $name))->filter()
            // Позади — в порядке журнала: он смотан на откатах, а порядок блоков в маршруте у веток «на себя» свой.
            ->reject(fn (Block $b) => $b->name === $current->name)->unique('name')->values();
        $ladder = $behind->push($current);
        $seen = $ladder->pluck('id')->all();
        while (true) {
            // Возврат назад («Отказываюсь» → снова приём) — не развилка пути вперёд: блоки раньше текущего не считаются.
            // Блоки из `to.block` — копии без этапов: берём те же блоки из уже загруженного маршрута, иначе каждый шаг
            // лестницы догружал этапы, исходы и цели по одному.
            $next = $current->nextBlocks($deal)->map(fn (Block $b) => $all->firstWhere('id', $b->id) ?? $b)
                // Блок конца («Сделка закрыта») стоит в маршруте раньше веток, дописанных после него, — возвратом он не бывает.
                ->reject(fn (Block $b) => $b->isDeadEnd() || in_array($b->id, $seen, true) || ($b->position < $current->position && ! $b->isFinal()));
            if ($guess && $next->count() > 1) {
                $names = $ladder->pluck('name')->all();
                $next = $next->reject(fn (Block $b) => in_array($b->name, $names, true))->sortBy('position')->take(1);
            }
            if ($next->count() !== 1) {
                return $ladder;
            }
            $current = $next->first();
            $seen[] = $current->id;
            $ladder->push($current);
        }
    }
}
