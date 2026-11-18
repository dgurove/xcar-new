<?php

namespace App\Offers;

use App\Workflow\Outcome;
use App\Workflow\Stage;
use Illuminate\Support\Collection;

/**
 * Путь сделки вперёд от текущего шага — главной дорогой (14.11.2026, «Уже продана…», «Договор уже подписан»): по исходам её
 * ветки (`Outcome::fits`), только вперёд по порядку маршрута, без тупиков («Отказ поставщика») и без ухода из сделки
 * («Я передумал», назад в продажу). На развилке — ближний по порядку шаг, шаг закрытия сделки — последним; ветка, что дальше не
 * ведёт, отбрасывается (`walk`).
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
        $all = self::stagesOf($current);
        $rank = $all->pluck('id')->flip();

        $start = $all->firstWhere('id', $current->id) ?? $current;
        if ($found = self::walk($deal, $start, $current, $stop, $all, $rank, [$current->id => true], [])) {
            return $found;
        }
        // Ни остановки, ни закрытия впереди — как раньше: ближним шагом, пока есть куда (что пройдено — `ReopenDeal`).
        [$stage, $seen, $passed] = [$start, [$current->id => true], []];
        while ($next = self::targets($stage, $deal, $all, $rank, $seen)->first()) {
            [$passed[], $seen[$next->id], $stage] = [$stage, true, $next];
        }

        return new self(null, $passed);
    }

    /**
     * Вглубь с возвратом (18.11.2026: «Уже продана» у Альфы вставала на «Письме с реквизитами» — ближний по порядку шаг «Осмотр
     * перед оплатой» ведёт только назад, а дальняя «Оплата счёта» — вперёд): развилка пробуется по порядку, ветка без пути к
     * остановке или закрытию отбрасывается.
     *
     * @param  Collection<int, Stage>  $all
     * @param  array<int, true>  $seen
     * @param  list<Stage>  $passed
     */
    private static function walk(Deal $deal, Stage $stage, Stage $current, callable $stop, Collection $all, Collection $rank, array $seen, array $passed): ?self
    {
        if ($stage->id !== $current->id && ($stage->offer_state === OfferState::Delivered || $stop($stage))) {
            return new self($stage, $passed);
        }
        foreach (self::targets($stage, $deal, $all, $rank, $seen) as $next) {
            if ($found = self::walk($deal, $next, $current, $stop, $all, $rank, $seen + [$next->id => true], [...$passed, $stage])) {
                return $found;
            }
        }

        return null;
    }

    /** @var array<int, Collection<int, Stage>> маршрут → его шаги, на один запрос (шторка «Уже продана» спрашивает трижды) */
    private static array $stages = [];

    private static ?int $stagesFor = null;

    /** @return Collection<int, Stage> */
    private static function stagesOf(Stage $current): Collection
    {
        if (self::$stagesFor !== spl_object_id(request())) {
            [self::$stages, self::$stagesFor] = [[], spl_object_id(request())];
        }

        return self::$stages[$current->workflow_id] ??= $current->workflow->stages()->with(['exits.to.block', 'block.stages.exits'])->get();
    }

    /**
     * Куда можно шагнуть дальше — по порядку: ближний шаг дороги первым, закрытие — последним (короткий выход «сразу закрыть» не
     * перепрыгивает договор и оплаты).
     *
     * @param  Collection<int, Stage>  $all
     * @return Collection<int, Stage>
     */
    private static function targets(Stage $stage, Deal $deal, Collection $all, Collection $rank, array $seen): Collection
    {
        $here = $rank[$stage->id] ?? -1;

        return $stage->exits->filter(fn (Outcome $e) => $e->to_stage_id && $e->fits($deal) && ! $e->isRetreat() && ! $e->acceptsBid())
            ->map(fn (Outcome $e) => $all->firstWhere('id', $e->to_stage_id))->filter()
            ->reject(fn (Stage $s) => isset($seen[$s->id]) || $s->block?->isDeadEnd()
                || in_array($s->offer_state, [OfferState::Open, OfferState::Draft, OfferState::Archived, OfferState::Cancelled, OfferState::Gallery], true))
            ->filter(fn (Stage $s) => ($rank[$s->id] ?? -1) > $here || $s->offer_state === OfferState::Delivered)
            ->unique('id')->sortBy(fn (Stage $s) => [$s->offer_state === OfferState::Delivered ? 1 : 0, $rank[$s->id]])->values();
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
