<?php

namespace App\Workflow;

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
        $blocks = self::ladder($position->stage, $journal->pluck('block')->all());
        $passed = true;

        return $blocks->map(function (Block $block) use (&$passed, $position, $journal) {
            $current = $block->id === $position->stage->block_id;
            $state = $current ? self::CURRENT : ($passed ? self::DONE : self::NEXT);
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
     * Этапы, в которые входило предложение, по порядку: блок, этап, выход, которым пришли, дата.
     *
     * @return Collection<int, array{at: Carbon, block: string, stage: ?string, exit: ?string}>
     */
    public static function journal(Offer $offer, Track $track, ?Carbon $since = null): Collection
    {
        return $offer->events()->where('type', OfferEventType::StageEntered)->when($since, fn ($q) => $q->where('created_at', '>=', $since))->oldest()->get()
            ->filter(fn ($e) => ($e->payload['track'] ?? Track::Sale->value) === $track->value)
            ->map(fn ($e) => ['at' => $e->created_at, 'block' => $e->payload['block'] ?? null, 'stage' => $e->payload['to'] ?? null, 'exit' => $e->payload['exit'] ?? null])
            ->filter(fn ($s) => $s['block'])
            ->values();
    }

    /**
     * Лестница: позади — где предложение было по журналу, текущий блок, впереди — пока путь однозначен. Развилка её
     * обрывает: у маршрута с двумя ветками покупки была бы видна и та, от которой отказались. Тупики срыва и возвраты
     * назад впереди не считаются. Блоки сравниваются по имени: у двух веток покупки блок «Согласуем с поставщиком» свой.
     *
     * @return Collection<int, Block>
     */
    public static function ladder(Stage $stage, array $passedNames): Collection
    {
        $all = $stage->workflow->blocks()->with('stages.exits.to.block')->get();
        $current = $all->firstWhere('id', $stage->block_id);
        $behind = collect($passedNames)->map(fn ($name) => $all->firstWhere('name', $name))->filter()
            ->reject(fn (Block $b) => $b->name === $current->name)->unique('name')
            // Позади — в порядке маршрута, а не журнала: после «Вернуть на этот шаг» журнал идёт вразнобой.
            ->sortBy('position')->values();
        $ladder = $behind->push($current);
        $seen = $ladder->pluck('id')->all();
        while (true) {
            $next = $current->nextBlocks()->reject(fn (Block $b) => $b->isDeadEnd() || in_array($b->id, $seen, true));
            if ($next->count() !== 1) {
                return $ladder;
            }
            $current = $next->first();
            $seen[] = $current->id;
            $ladder->push($current);
        }
    }
}
