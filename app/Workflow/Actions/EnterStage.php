<?php

namespace App\Workflow\Actions;

use App\Offers\Actions\ChangeOfferState;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Offers\Slots;
use App\Users\User;
use App\Workflow\Events\StageEntered;
use App\Workflow\Outcome;
use App\Workflow\Position;
use App\Workflow\Requirement;
use App\Workflow\Stage;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
use Illuminate\Support\Carbon;

/**
 * Поставить оффер на этап: позиция и срок, состояние или место машины, просьба к менеджеру, лента, событие.
 * Этап оплаты, а счёта у сделки нет — ход наш (`waits_for = us`): без часов и без просьбы менеджеру, её заведёт
 * выставленный счёт (`PassTurnOnInvoice`). Иначе обе стороны ждали друг друга.
 */
final class EnterStage
{
    public function __construct(private ChangeOfferState $changeState, private SetCarPlace $setPlace) {}

    /** @param  bool  $back  откат («Отменить шаг», «Вернуть на этот шаг»): журнал пути сматывается до прежнего входа сюда */
    public function __invoke(Offer $offer, Stage $to, ?User $by = null, array $payload = [], ?Outcome $exit = null, bool $back = false): Offer
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
            // Как у публикации кнопкой: через N дней в 17:00 (`Slots::closeFor`), N — срок этапа «Приём» в днях.
            $offer->bids_close_at = Slots::closeFor($now, $to->limit_minutes ? max(1, intdiv($to->limit_minutes, 1440)) : null);
            $offer->save();
        }

        $ours = $track === Track::Sale && $deal?->isActive() && $to->isPayStep() && ! $deal->hasManagerInvoice();
        $position = Position::updateOrCreate(['offer_id' => $offer->id, 'track' => $track->value], [
            'stage_id' => $to->id,
            'waits_for' => $ours ? WaitsFor::Us : null,
            'entered_at' => $now,
            'block_entered_at' => $from && $from->block_id === $to->block_id && $position?->block_entered_at ? $position->block_entered_at : $now,
            'deadline_at' => $ours ? null : $to->deadlineFor($offer, $now),
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

        if (! $ours && $deal && $deal->isActive() && $deal->buyer_id && $to->awaitsManager($deal)) {
            Requirement::askFor($deal, $to, $position);
        }

        $offer->log(OfferEventType::StageEntered, $by, [
            'track' => $track->value, 'from' => $from?->name, 'to' => $to->name, 'block' => $to->block?->name, 'exit' => $exit?->label,
            'from_id' => $from?->id, 'to_id' => $to->id, ...($back ? ['back' => true] : []),
        ]);
        StageEntered::dispatch($offer, $track, $from, $to, $exit, $by, $deal);

        return $offer;
    }
}
