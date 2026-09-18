<?php

namespace App\Workflow\Actions;

use App\Offers\Actions\ChangeOfferState;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Users\User;
use App\Workflow\DeadlineSource;
use App\Workflow\Events\StageEntered;
use App\Workflow\Outcome;
use App\Workflow\Position;
use App\Workflow\Requirement;
use App\Workflow\Stage;
use App\Workflow\Track;
use Illuminate\Support\Carbon;

/** Поставить оффер на этап: позиция и срок, состояние или место машины, просьба к менеджеру, лента, событие. */
final class EnterStage
{
    public function __construct(private ChangeOfferState $changeState, private SetCarPlace $setPlace) {}

    public function __invoke(Offer $offer, Stage $to, ?User $by = null, array $payload = [], ?Outcome $exit = null): Offer
    {
        $to->loadMissing(['workflow', 'block', 'exits.to']);
        $track = $to->workflow->track;
        $now = Carbon::now();

        $position = Position::where('offer_id', $offer->id)->where('track', $track)->with('stage')->first();
        $from = $position?->stage;
        // Сделку запоминаем до смены состояния: конечный этап её закрывает, а написать менеджеру надо именно тогда.
        $deal = $offer->deal()->with('buyer')->first();

        if ($from) {
            Requirement::where('offer_id', $offer->id)->where('stage_id', $from->id)->whereNull('done_at')
                ->update(['done_at' => $now, 'answer' => json_encode(['closed_by' => 'stage'])]);
        }

        if ($track === Track::Sale && $to->offer_state === OfferState::Open
            && (! $offer->bids_close_at || $offer->bids_close_at->isPast())) {
            $offer->bids_close_at = $now->copy()->addMinutes($to->limit_minutes ?? (int) config('xcar.bids_window_days') * 1440);
            $offer->save();
        }

        $position = Position::updateOrCreate(['offer_id' => $offer->id, 'track' => $track->value], [
            'stage_id' => $to->id,
            'entered_at' => $now,
            'block_entered_at' => $from && $from->block_id === $to->block_id && $position?->block_entered_at ? $position->block_entered_at : $now,
            'deadline_at' => $this->deadline($offer, $to, $now),
            'reminded_at' => null,
            'overdue_at' => null,
            'payload' => $payload ?: null,
        ]);
        $offer->unsetRelation('positions');

        if ($track === Track::Sale && $to->offer_state && $offer->state !== $to->offer_state && $offer->state->allows($to->offer_state)) {
            $offer = ($this->changeState)($offer, $to->offer_state, $by, followRoute: false);
        }
        if ($track === Track::Service && $to->car_place) {
            $offer = ($this->setPlace)($offer, $to->car_place, $by);
        }

        if ($to->awaitsManager() && $deal && $deal->isActive() && $deal->buyer_id) {
            Requirement::create([
                'offer_id' => $offer->id,
                'deal_id' => $deal->id,
                'stage_id' => $to->id,
                'user_id' => $deal->buyer_id,
                'title' => $to->managerTitle(),
                'text' => $to->managerText(),
                'asks' => $to->asks,
                'fields' => $to->fields ?? [],
                'due_at' => $position->deadline_at,
            ]);
        }

        $offer->log(OfferEventType::StageEntered, $by, [
            'track' => $track->value, 'from' => $from?->name, 'to' => $to->name, 'block' => $to->block?->name, 'exit' => $exit?->label,
        ]);
        StageEntered::dispatch($offer, $track, $from, $to, $exit, $by, $deal);

        return $offer;
    }

    private function deadline(Offer $offer, Stage $to, Carbon $now): ?Carbon
    {
        return match ($to->deadline_source) {
            DeadlineSource::BidsClose => $offer->bids_close_at,
            DeadlineSource::InsurerDeadline => $offer->insurer_deadline_at?->copy()->endOfDay(),
            DeadlineSource::Own => $to->deadlineFrom($now),
        };
    }
}
