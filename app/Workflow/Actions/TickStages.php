<?php

namespace App\Workflow\Actions;

use App\Offers\OfferEventType;
use App\Workflow\Actor;
use App\Workflow\Events\StageDue;
use App\Workflow\Position;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Раз в минуту: исходы по времени, просрочки, напоминания. Приём подтверждений часы не закрывают — он закрывается сам по bids_close_at. */
final class TickStages
{
    private const REMIND_MIN = 30;

    private const REMIND_MAX = 1440;

    /** За сколько минут до срока напомнить: десятая часть всего срока шага (от входа или начала хода), от 30 мин до суток. */
    public static function remindBefore(Position $position): int
    {
        $span = (int) abs($position->deadline_at->diffInMinutes($position->entered_at));

        return max(self::REMIND_MIN, min(self::REMIND_MAX, intdiv($span, 10)));
    }

    public function __construct(private TakeExit $takeExit) {}

    public function __invoke(): array
    {
        $counts = ['advanced' => 0, 'overdue' => 0, 'reminded' => 0];
        $now = Carbon::now();

        // Обход по id, а не страницами: обработанная строка выпадает из фильтра и
        // сдвинула бы следующую страницу. Одна упавшая позиция не останавливает остальные.
        foreach (Position::whereNotNull('deadline_at')->where('deadline_at', '<=', $now)->whereNull('overdue_at')
            ->with(['offer', 'stage.exits.to', 'stage.workflow'])->lazyById() as $position) {
            try {
                if ($exit = $position->stage->timerExit()) {
                    ($this->takeExit)($position->offer, $exit, Actor::Timer);
                    $counts['advanced']++;

                    continue;
                }
                $position->update(['overdue_at' => $now]);
                $position->offer->log(OfferEventType::StageOverdue, null, ['stage' => $position->stage->name]);
                StageDue::dispatch($position->offer, $position, true);
                $counts['overdue']++;
            } catch (Throwable $e) {
                // Исход не прошёл (например, публикации не хватает фото): позиция помечается
                // просроченной, чтобы не биться об неё каждую минуту, и всплывает бейджем.
                Log::warning('Часы: исход по времени не прошёл', ['offer' => $position->offer_id, 'stage' => $position->stage_id, 'error' => $e->getMessage()]);
                $position->update(['overdue_at' => $now]);
                $counts['overdue']++;
            }
        }

        // «Срок подходит» — за десятую часть срока шага, от получаса до суток (19.01.2027: за час до срока у шага в час
        // напоминание приходило сразу, у недельного — за час, когда уже поздно).
        foreach (Position::whereNotNull('deadline_at')->where('deadline_at', '>', $now)->where('deadline_at', '<=', $now->copy()->addMinutes(self::REMIND_MAX))
            ->whereNull('reminded_at')->with(['offer', 'stage'])->lazyById() as $position) {
            if ($position->deadline_at->gt($now->copy()->addMinutes(self::remindBefore($position)))) {
                continue;
            }
            try {
                $position->update(['reminded_at' => $now]);
                $position->offer->log(OfferEventType::StageReminded, null, ['stage' => $position->stage->name]);
                StageDue::dispatch($position->offer, $position, false);
                $counts['reminded']++;
            } catch (Throwable $e) {
                Log::warning('Часы: напоминание не ушло', ['offer' => $position->offer_id, 'error' => $e->getMessage()]);
            }
        }

        return $counts;
    }
}
