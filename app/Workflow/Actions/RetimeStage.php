<?php

namespace App\Workflow\Actions;

use App\Offers\OfferEventType;
use App\Users\User;
use App\Workflow\DeadlineSource;
use App\Workflow\Position;
use App\Workflow\Requirement;
use App\Workflow\Stage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Срок этапа поменяли — он доходит до сделок, что уже стоят на этапе (19.01.2027, начальник: «если сменим 3 дня на 5 — точно
 * поменяется везде?»; до того срок писался в позицию снимком при входе и правка доходила только до новых).
 *
 * Откуда тикали часы, не важно (вход, письмо поставщику, выставленный счёт): старт = старый срок минус старая длительность,
 * новый срок = старт плюс новая. Позиция без срока, потому что ход ещё не начался (этап оплаты без счёта, письмо не ушло,
 * поставщик ответил — `waits_for` у позиции), так и остаётся; был этап без срока — часы от входа. Сняли срок — снимается и у
 * позиций. Напоминание и «просрочено» сбрасываются, если новый срок впереди; открытая просьба менеджеру — тот же срок.
 */
final class RetimeStage
{
    /** @return int сколько сделок получили новый срок */
    public function __invoke(Stage $stage, ?int $oldMinutes, bool $oldWorkdays, User $by): int
    {
        if ($stage->deadline_source !== DeadlineSource::Own || ($oldMinutes === $stage->limit_minutes && $oldWorkdays === (bool) $stage->limit_workdays)) {
            return 0;
        }
        $was = Stage::durationLabel($oldMinutes, $oldWorkdays) ?? 'без срока';
        $now = $stage->limit_minutes === null ? 'без срока' : Stage::durationLabel($stage->limit_minutes, (bool) $stage->limit_workdays);
        $count = 0;

        foreach (Position::where('stage_id', $stage->id)->with('offer')->get() as $position) {
            $deadline = $this->deadline($stage, $position, $oldMinutes, $oldWorkdays);
            if ($deadline === false || $deadline?->equalTo($position->deadline_at) || ($deadline === null && $position->deadline_at === null)) {
                continue;
            }
            DB::transaction(function () use ($position, $deadline, $stage, $by, $was, $now) {
                $future = $deadline?->isFuture() ?? true;
                $position->update(['deadline_at' => $deadline] + ($future ? ['reminded_at' => null, 'overdue_at' => null] : []));
                Requirement::where('offer_id', $position->offer_id)->where('stage_id', $stage->id)->whereNull('done_at')->update(['due_at' => $deadline]);
                $position->offer?->log(OfferEventType::Note, $by, ['text' => "Срок этапа «{$stage->name}» изменён: {$was} → {$now}"]);
            });
            $count++;
        }

        return $count;
    }

    /** Новый срок позиции; false — не трогать. */
    private function deadline(Stage $stage, Position $position, ?int $oldMinutes, bool $oldWorkdays): Carbon|null|false
    {
        if ($stage->limit_minutes === null) {
            return null;
        }
        if ($position->deadline_at === null) {
            // Часы ещё не пошли по ходу (счёт, письмо, ответ поставщика) — пойдут, когда начнётся ход; без срока были — от входа.
            return $oldMinutes === null && $position->waits_for === null ? Stage::addLimit($position->entered_at, $stage->limit_minutes, (bool) $stage->limit_workdays) : false;
        }
        $start = $oldMinutes === null ? $position->entered_at : Stage::subLimit($position->deadline_at, $oldMinutes, $oldWorkdays);

        return Stage::addLimit($start, $stage->limit_minutes, (bool) $stage->limit_workdays);
    }

    /** Сколько сделок сейчас на этапе — подпись галки «Применить к N сделкам». */
    public static function occupied(Stage $stage): int
    {
        return Position::where('stage_id', $stage->id)->count();
    }
}
