<?php

namespace App\Offers\Actions;

use App\Billing\Actions\IssueAgentFee;
use App\Billing\Actions\RecordPayment;
use App\Billing\PaymentSource;
use App\Offers\Deal;
use App\Offers\DealDates;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Users\User;
use App\Workflow\Actions\TakeExit;
use App\Workflow\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Правка «Продано» / «Оплачено» нажатием во «Все» (14.11.2026, `DealDates`): дата — то, что было на деле, и система идёт за ней:
 * — «Продано» поставили, а наши счета не оплачены — оплачиваются этой датой (иначе «Нам должны» врал бы); очистить оплаченное
 *   системой нельзя — оплату отменяют в «Деньгах» сделки;
 * — «Оплачено» поставили на шаге нашей оплаты поставщику — шаг проходит выходом сотрудника с этой датой; очистили у закрытой,
 *   что прошла оплату поставщику, — сделка снова в работе на этом шаге (`ReopenDeal`).
 */
final class SetDealDate
{
    public function __construct(private RecordPayment $pay, private IssueAgentFee $fee, private TakeExit $take, private ReopenDeal $reopen) {}

    public function __invoke(Deal $deal, string $what, ?Carbon $at, User $by): Deal
    {
        return DB::transaction(function () use ($deal, $what, $at, $by) {
            $deal = Deal::whereKey($deal->id)->lockForUpdate()->firstOrFail();
            $offer = Offer::findOrFail($deal->offer_id);
            $deal->setRelation('offer', $offer);
            $column = $what === DealDates::SOLD ? DealDates::soldColumn($deal) : DealDates::paidColumn($deal);
            if (! $column || ! in_array($deal->state, [DealState::Active, DealState::Done], true)) {
                throw ValidationException::withMessages(['at' => 'У этой сделки такой даты нет']);
            }
            $was = $deal->{$column};
            $at = $at ? ($at->isToday() ? now() : $at->copy()->setTime(12, 0)) : null;

            if ($what === DealDates::SOLD && $column === 'paid_in_at') {
                if ($at && ! $was) {
                    foreach (MarkSoldDeal::unpaid($deal) as $bill) {
                        ($this->pay)($bill, $by, $bill->remaining(), $at, PaymentSource::Bank, note: 'Оплачено: дата во «Все»');
                    }
                    $deal->refresh()->fullyPaid() && ($this->fee)($deal->setRelation('offer', $offer), $by);
                } elseif (! $at && $deal->fullyPaid()) {
                    throw ValidationException::withMessages(['at' => 'Счета оплачены — оплату отменяют в «Деньгах» сделки']);
                }
            }
            if ($what === DealDates::PAID && ! $at && $was && $deal->state === DealState::Done && ReopenDeal::allows($deal, $by)) {
                // Закрытая: оплата поставщику снова впереди — сделка возвращается на этот шаг.
                $step = ReopenDeal::stages($deal)->last(fn ($row) => $row['stage']->paysOut())['stage'] ?? null;
                if ($step) {
                    return ($this->reopen)($deal, $step, $by, undoSold: false);
                }
            }
            $deal->refresh()->forceFill([$column => $at])->save();
            $offer->log(OfferEventType::Note, $by, ['text' => ($what === DealDates::SOLD ? 'Продано' : 'Оплачено поставщику').': '
                .($was?->format('d.m.Y') ?? 'нет').' → '.($at?->format('d.m.Y') ?? 'нет')]);

            // Оплатили поставщику, а сделка на шаге нашей оплаты — шаг проходит («Поставщику оплачено» с этой датой).
            $stage = $offer->stage();
            if ($what === DealDates::PAID && $at && $deal->isActive() && $stage?->paysOut()
                && ($exit = $stage->exitsFor(Actor::Staff, $deal)->first(fn ($e) => ! $e->to?->block?->isDeadEnd()))) {
                $field = $stage->payFields()[0]['label'] ?? null;
                ($this->take)($offer, $exit, Actor::Staff, $by, $field ? ['out' => [$field => $at->translatedFormat('j F Y')]] : []);
                $deal->refresh()->forceFill([$column => $at])->save();
            }

            return $deal->fresh();
        });
    }
}
