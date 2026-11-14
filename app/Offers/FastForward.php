<?php

namespace App\Offers;

use App\Workflow\Outcome;
use App\Workflow\Stage;
use Illuminate\Support\Collection;

/**
 * Путь сделки вперёд от текущего шага — главной дорогой (14.11.2026, «Уже продана…», «Договор уже подписан»): по исходам её
 * ветки (`Outcome::fits`), только вперёд по порядку маршрута, без тупиков («Отказ поставщика») и без ухода из сделки
 * («Я передумал», назад в продажу). На развилке — ближний по порядку шаг, шаг закрытия сделки — последним.
 * Где встать решает вызывающий: первый шаг, для которого `$stop` — да, иначе шаг закрытия («Сделка закрыта»).
 */
final class FastForward
{
    /** @param  list<Stage>  $passed  шаги, что будут пройдены (без остановки) */
    private function __construct(public readonly ?Stage $stop, public readonly array $passed) {}

    /** Остаться на текущем шаге: он сам и есть то, что не сделано. */
    public static function here(Stage $current): self
    {
        return new self($current, []);
    }

    /** @param  callable(Stage): bool  $stop */
    public static function from(Deal $deal, Stage $current, callable $stop): self
    {
        $all = $current->workflow->stages()->with(['exits.to.block', 'block.stages.exits'])->get();
        $rank = $all->pluck('id')->flip();
        $passed = [];
        $seen = [$current->id => true];
        $stage = $all->firstWhere('id', $current->id) ?? $current;
        while (true) {
            if ($stage->id !== $current->id && ($stage->offer_state === OfferState::Delivered || $stop($stage))) {
                return new self($stage, $passed);
            }
            $next = self::next($stage, $deal, $all, $rank, $seen);
            if (! $next) {
                return new self(null, $passed);
            }
            $passed[] = $stage;
            $seen[$next->id] = true;
            $stage = $next;
        }
    }

    /** @param  Collection<int, Stage>  $all */
    private static function next(Stage $stage, Deal $deal, Collection $all, Collection $rank, array $seen): ?Stage
    {
        $here = $rank[$stage->id] ?? -1;
        $targets = $stage->exits->filter(fn (Outcome $e) => $e->to_stage_id && $e->fits($deal) && ! $e->isRetreat() && ! $e->acceptsBid())
            ->map(fn (Outcome $e) => $all->firstWhere('id', $e->to_stage_id))->filter()
            ->reject(fn (Stage $s) => isset($seen[$s->id]) || $s->block?->isDeadEnd()
                || in_array($s->offer_state, [OfferState::Open, OfferState::Draft, OfferState::Archived, OfferState::Cancelled, OfferState::Gallery], true))
            ->filter(fn (Stage $s) => ($rank[$s->id] ?? -1) > $here || $s->offer_state === OfferState::Delivered);

        // Ближний шаг дороги; закрытие — последним: короткий выход «сразу закрыть» не перепрыгивает договор и оплаты.
        return $targets->sortBy(fn (Stage $s) => [$s->offer_state === OfferState::Delivered ? 1 : 0, $rank[$s->id]])->first();
    }

    public function closes(): bool
    {
        return $this->stop?->offer_state === OfferState::Delivered;
    }

    /** Пройденные шаги, где платят поставщику (`paysOut`) или менеджер оплачивает (`isPayStep`). */
    public function passesPayment(): bool
    {
        return collect($this->passed)->contains(fn (Stage $s) => $s->paysOut() || $s->isPayStep());
    }

    public function passesSupplierPayout(): bool
    {
        return collect($this->passed)->contains(fn (Stage $s) => $s->paysOut());
    }
}
