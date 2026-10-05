<?php

namespace App\Offers\Actions;

use App\Garage\Actions\ReserveCar;
use App\Garage\GaragePayer;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\CommissionMode;
use App\Offers\Deal;
use App\Offers\DealScheme;
use App\Offers\DealState;
use App\Offers\Events\BidAccepted;
use App\Offers\Events\BidDeclined;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Users\User;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Track;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Принять подтверждение: сделка, оффер в сделке. Остальные подтверждения не отклоняются — это резерв:
 * выбранный передумал, и машину отдают другому подтвердившему тем же действием. Тогда прежняя сделка
 * отменяется (прежнему победителю — «отклонено»), этап маршрута остаётся, его просьба переходит новому.
 * Деньги фиксируются тут же: закупочная снимком, агентское вознаграждение и режим —
 * менеджер их не увидит, пока по сделке не выставлен счёт.
 *
 * «Страхователю по ДКП» (05.10.2026, `DealScheme::OwnerDkp`): сколько покупатель отдаст собственнику по договору —
 * своей суммой (`owner_price`; Т-Страхование отдаёт дешевле закупочной — взаимозачёт), вознаграждение менеджер удерживает сам,
 * а счёт «Подбор ТС» на разницу выставляется сразу — со ссылкой на оплату (`EnsurePayLink`).
 *
 * Гаражное подтверждение («В гараж») — та же сделка без цены и вознаграждения, но с тем, кто платит поставщику:
 * маршрут вендора ведёт её как обычную (или гаражной веткой, когда платим мы), машина сразу встаёт менеджеру в
 * гараж «Ждёт страховую», а конец маршрута ставит её на доставку.
 */
final class AcceptBid
{
    public function __construct(private ChangeOfferState $changeState, private CancelDeal $cancelDeal, private EnterStage $enterStage, private ReserveCar $reserve, private IssueSelectionInvoice $selection) {}

    public function __invoke(Bid $bid, User $by, ?int $commission = null, CommissionMode $mode = CommissionMode::Payout, ?GaragePayer $payer = null, DealScheme $scheme = DealScheme::Ours, ?int $ownerPrice = null): Deal
    {
        return DB::transaction(function () use ($bid, $by, $commission, $mode, $payer, $scheme, $ownerPrice) {
            $bid = Bid::whereKey($bid->id)->lockForUpdate()->firstOrFail();
            if ($bid->state !== BidState::Active) {
                throw ValidationException::withMessages(['bid' => 'Подтверждение уже '.mb_strtolower($bid->state->label())]);
            }
            $offer = $bid->offer;

            // Отдаём другому: прежняя сделка отменяется, прежний победитель узнаёт об этом.
            $previous = $offer->deal()->with('bid')->first();
            if ($previous) {
                ($this->cancelDeal)($previous, $by);
                if ($previous->bid) {
                    BidDeclined::dispatch($previous->bid->refresh(), $by);
                }
                $offer->unsetRelation('deal');
            }

            $bid->update(['state' => BidState::Accepted, 'decided_at' => now(), 'decided_by' => $by->id]);
            if ($bid->isGarage()) {
                // Без гаражной ветки у маршрута (Т-Страхование) поставщику платит менеджер — путём обычной сделки.
                $payer = $offer->garageBranch() ? ($payer ?? GaragePayer::Us) : GaragePayer::Manager;
                [$commission, $mode, $scheme] = [null, CommissionMode::Payout, DealScheme::Ours];
            } else {
                $payer = null;
            }
            // По ДКП вознаграждение остаётся у менеджера: покупатель отдаёт ему, а нам он платит только подбор.
            if ($scheme === DealScheme::OwnerDkp) {
                $mode = CommissionMode::Withheld;
            }
            $deal = Deal::create(['offer_id' => $offer->id, 'bid_id' => $bid->id, 'buyer_id' => $bid->user_id, 'amount' => $bid->amount, 'state' => DealState::Active,
                'cost' => $offer->floor_price, 'owner_price' => $scheme === DealScheme::OwnerDkp ? ($ownerPrice ?? $offer->owner_price ?? $offer->floor_price) : null,
                'commission' => $commission, 'commission_mode' => $mode,
                'scheme' => $scheme, 'garage_payer' => $payer]);
            $offer->log(OfferEventType::BidAccepted, $by, array_filter(['bid_id' => $bid->id, 'amount' => $bid->amount, 'commission' => $commission, 'mode' => $mode->value, 'garage' => $payer?->value,
                'dkp' => $deal->ownerPrice()], fn ($v) => $v !== null));
            if ($deal->isDkp()) {
                ($this->selection)($deal->setRelation('offer', $offer), $by);
            }
            if ($deal->isGarage()) {
                ($this->reserve)($deal, $by);
            }
            if ($previous && $offer->state === OfferState::Sold) {
                // Этап тот же, просьба к менеджеру — новому (вход на этап заводит её по сделке). Сменилась ветка
                // (гараж «платим мы» ↔ остальные) — с начала сделки: этап чужой ветки этой сделке не подходит.
                $stage = $offer->stage();
                if ($stage && $previous->isGarageUs() !== $deal->isGarageUs()) {
                    $stage = $stage->workflow->dealEntry($deal) ?? $stage;
                }
                if ($stage) {
                    ($this->enterStage)($offer, $stage, $by);
                }
            } else {
                $offer = ($this->changeState)($offer, OfferState::Sold, $by);
                // Маршрута нет — ждать со страховой нечего: сразу в гараж, на доставку.
                if ($deal->isGarage() && ! $offer->position(Track::Sale)) {
                    ($this->changeState)($offer, OfferState::Garage, $by);
                }
            }
            BidAccepted::dispatch($bid, $by);

            return $deal;
        });
    }
}
