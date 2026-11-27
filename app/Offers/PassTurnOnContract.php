<?php

namespace App\Offers;

use App\Chats\StepAsk;
use App\Workflow\Asks;
use App\Workflow\Position;
use App\Workflow\Requirement;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
use Illuminate\Support\Carbon;

/**
 * Ход на шаге «Подписанный договор» идёт за нашим договором (27.11.2026, владелец по 495181/2026: «почему написали „Нужно
 * приложить договор“, если он нами ещё не отправлен?»), как ход оплаты за счётом (`Billing\Listeners\PassTurnOnInvoice`).
 * Пока договор менеджеру не отправлен — ход наш (`Deal::awaitsOurContract`, `EnterStage`); «Отправить менеджеру» — ход его:
 * часы от сейчас, просьба шага и «Привет, нужно приложить…» в чат (`StepAsk`). Отозвали — ход снова наш, просьба снята.
 */
final class PassTurnOnContract
{
    public static function shown(Deal $deal): void
    {
        $position = self::position($deal);
        if (! $position || $position->waits_for !== WaitsFor::Us || ! $deal->isActive() || ! $deal->buyer_id) {
            return;
        }
        $position->update(['waits_for' => null, 'deadline_at' => $position->stage->deadlineFor($deal->offer, Carbon::now()), 'reminded_at' => null, 'overdue_at' => null]);
        $open = Requirement::where('deal_id', $deal->id)->where('stage_id', $position->stage_id)->whereNull('done_at')->exists();
        if (! $open && $position->stage->awaitsManager($deal)) {
            StepAsk::post(Requirement::askFor($deal, $position->stage, $position));
        }
    }

    public static function recalled(Deal $deal): void
    {
        $position = self::position($deal);
        if (! $position || $position->waits_for === WaitsFor::Us || ! $deal->fresh()->awaitsOurContract($position->stage)) {
            return;
        }
        $position->update(['waits_for' => WaitsFor::Us, 'deadline_at' => null, 'reminded_at' => null, 'overdue_at' => null]);
        Requirement::where('deal_id', $deal->id)->where('stage_id', $position->stage_id)->whereNull('done_at')->get()->each->delete();
    }

    /** Позиция продажи на шаге, что просит подписанный договор. */
    private static function position(Deal $deal): ?Position
    {
        $position = Position::where('offer_id', $deal->offer_id)->where('track', Track::Sale)->with('stage.exits')->first();

        return $position?->stage && $position->stage->asks === Asks::Document && $position->stage->aboutContract() ? $position : null;
    }
}
