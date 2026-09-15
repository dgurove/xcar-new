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

        $before = (int) config('xcar.remind_before_minutes');
        if ($before > 0) {
            foreach (Position::whereNotNull('deadline_at')->where('deadline_at', '>', $now)->where('deadline_at', '<=', $now->copy()->addMinutes($before))
                ->whereNull('reminded_at')->with(['offer', 'stage'])->lazyById() as $position) {
                try {
                    $position->update(['reminded_at' => $now]);
                    $position->offer->log(OfferEventType::StageReminded, null, ['stage' => $position->stage->name]);
                    StageDue::dispatch($position->offer, $position, false);
                    $counts['reminded']++;
                } catch (Throwable $e) {
                    Log::warning('Часы: напоминание не ушло', ['offer' => $position->offer_id, 'error' => $e->getMessage()]);
                }
            }
        }

        return $counts;
    }
}
