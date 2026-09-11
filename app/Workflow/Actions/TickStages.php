<?php

namespace App\Workflow\Actions;

use App\Offers\Actions\ChangeOfferState;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Workflow\Events\StageDue;
use App\Workflow\Actor;
use App\Workflow\Position;
use Illuminate\Support\Carbon;

/** Раз в минуту: исходы по времени, просрочки, напоминания, закрытие приёма ставок. */
final class TickStages
{
    public function __construct(private TakeExit $takeExit, private ChangeOfferState $changeState) {}

    public function __invoke(): array
    {
        $counts = ['advanced' => 0, 'overdue' => 0, 'reminded' => 0, 'closed' => 0];
        $now = Carbon::now();

        Position::whereNotNull('deadline_at')->where('deadline_at', '<=', $now)->whereNull('overdue_at')
            ->with(['offer', 'stage.exits.to', 'stage.workflow'])
            ->each(function (Position $position) use (&$counts, $now) {
                if ($exit = $position->stage->timerExit()) {
                    ($this->takeExit)($position->offer, $exit, Actor::Timer);
                    $counts['advanced']++;

                    return;
                }
                $position->update(['overdue_at' => $now]);
                $position->offer->log(OfferEventType::StageOverdue, null, ['stage' => $position->stage->name]);
                StageDue::dispatch($position->offer, $position, true);
                $counts['overdue']++;
            });

        $before = (int) config('xcar.remind_before_minutes');
        if ($before > 0) {
            Position::whereNotNull('deadline_at')->where('deadline_at', '>', $now)->where('deadline_at', '<=', $now->copy()->addMinutes($before))
                ->whereNull('reminded_at')->with(['offer', 'stage'])
                ->each(function (Position $position) use (&$counts, $now) {
                    $position->update(['reminded_at' => $now]);
                    $position->offer->log(OfferEventType::StageReminded, null, ['stage' => $position->stage->name]);
                    StageDue::dispatch($position->offer, $position, false);
                    $counts['reminded']++;
                });
        }

        Offer::where('state', OfferState::Open)->whereNotNull('bids_close_at')->where('bids_close_at', '<=', $now)
            ->each(function (Offer $offer) use (&$counts) {
                ($this->changeState)($offer, OfferState::Closed, null);
                $counts['closed']++;
            });

        return $counts;
    }
}
