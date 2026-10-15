<?php

namespace App\Offers\Actions;

use App\Garage\Actions\ReserveCar;
use App\Garage\GaragePayer;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\CommissionMode;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\DealScheme;
use App\Offers\DealState;
use App\Offers\Events\BidAccepted;
use App\Offers\Events\BidDeclined;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Support\Money;
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
 * Схема оплаты (05.10.2026, `DealScheme`): ДКП и «страховой напрямую» — сколько покупатель отдаёт не нам (`owner_price`;
 * меньше закупочной — взаимозачёт), вознаграждение менеджер удерживает сам, счёт «Подбор ТС» со ссылкой;
 * ПРАЙМ — счёт плательщику, когда менеджер его укажет. Гаражная «платит менеджер» — наша доля (`share`) по ссылке,
 * у ПРАЙМ ещё счёт за машину на закупочную (`SyncDealInvoices`). Все счета — только после согласования с поставщиком
 * (`AgreeDeal`, 09.10.2026): до ответа поставщика менеджеру платить не за что.
 *
 * Гаражное подтверждение («В гараж») — та же сделка без цены и вознаграждения, но с тем, кто платит поставщику:
 * маршрут вендора ведёт её как обычную (или гаражной веткой, когда платим мы), машина сразу встаёт менеджеру в
 * гараж «Ждёт машину»: привезёт её вывоз (`EnsurePickup` — в контроллере, вместе с выбором, кто везёт).
 */
final class AcceptBid
{
    public function __construct(private ChangeOfferState $changeState, private CancelDeal $cancelDeal, private EnterStage $enterStage, private ReserveCar $reserve, private AgreeDeal $agree) {}

    /** @param  bool  $loss  согласие принять не выше закупочной (переспросили в шторке «Принять») */
    public function __invoke(Bid $bid, User $by, ?int $commission = null, CommissionMode $mode = CommissionMode::Payout, ?GaragePayer $payer = null, DealScheme $scheme = DealScheme::Prime, ?int $ownerPrice = null, ?int $share = null, bool $loss = false): Deal
    {
        return DB::transaction(function () use ($bid, $by, $commission, $mode, $payer, $scheme, $ownerPrice, $share, $loss) {
            $bid = Bid::whereKey($bid->id)->lockForUpdate()->firstOrFail();
            if ($bid->state !== BidState::Active) {
                throw ValidationException::withMessages(['bid' => 'Подтверждение уже '.mb_strtolower($bid->state->label())]);
            }
            $offer = $bid->offer;
            // Не выше закупочной — только с согласия (14.10.2026: сделка 3308 ушла в минус молча). Закупочную могли поднять
            // после того, как открыли шторку, — тогда переспрашиваем.
            // Закупочная менеджера — какой она была, когда он подтверждал, и закреплена навсегда (15.10.2026: «страховая
            // скинула — наша заслуга»); нынешняя, если другая, — закупочная поставщика, только админу.
            $cost = $bid->frozenCost();
            if (! $bid->isGarage() && ! $loss && $cost && $bid->amount !== null && $bid->amount <= $cost) {
                throw ValidationException::withMessages(['bid' => 'Цена подтверждения '.Money::rub($bid->amount).' не выше закупочной '.Money::rub($cost).': примите ещё раз']);
            }

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
                // Совкомбанк (менеджер платит ПРАЙМ по счёту) и маршрут без гаражной ветки (Т-Страхование) — платит менеджер,
                // путём обычной сделки (`Offer::garagePayerFixed`).
                $payer = $offer->garagePayerFixed() ?? $payer ?? GaragePayer::Us;
                [$commission, $mode] = [null, CommissionMode::Payout];
                // Наша доля — только у «платит менеджер»: он платит её по ссылке сразу (05.10.2026).
                // Совкомбанк: доли нет — машина уходит без оплаты, нашу часть даёт продажа (`Deal::namesBuyer`).
                $share = $payer === GaragePayer::Manager && ! $offer->vendor?->isCommission() ? $share : null;
            } else {
                [$payer, $share] = [null, null];
            }
            // Покупатель платит не нам (ДКП, страховой) — вознаграждение остаётся у менеджера, нам он платит подбор.
            if ($scheme->paysSelection()) {
                $mode = CommissionMode::Withheld;
            }
            $deal = Deal::create(['offer_id' => $offer->id, 'bid_id' => $bid->id, 'buyer_id' => $bid->user_id, 'amount' => $bid->amount, 'state' => DealState::Active,
                'cost' => $cost, 'supplier_cost' => $offer->floor_price && $offer->floor_price !== $cost ? $offer->floor_price : null,
                'owner_price' => $scheme->paysSelection() && ! $bid->isGarage() ? ($ownerPrice ?? $offer->owner_price ?? $cost) : null,
                'share' => $share, 'commission' => $commission, 'commission_mode' => $mode, 'scheme' => $scheme, 'garage_payer' => $payer]);
            $offer->log(OfferEventType::BidAccepted, $by, array_filter(['bid_id' => $bid->id, 'amount' => $bid->amount, 'commission' => $deal->commission, 'mode' => $mode->value, 'garage' => $payer?->value,
                'scheme' => $scheme->value, 'dkp' => $deal->ownerPrice(), 'share' => $share], fn ($v) => $v !== null));
            $deal->setRelation('offer', $offer);
            // Покупатель по договору у супер-покупателя — он сам, выбирать некого (`DealContract::for`); вознаграждения у
            // его сделки нет — его снимает сама сделка (`Deal::booted`).
            if ($deal->isDirect()) {
                DealContract::for($deal);
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
                // Маршрута нет — ждать со страховой нечего: сразу «В гараже», бумаг нет.
                if ($deal->isGarage() && ! $offer->position(Track::Sale)) {
                    ($this->changeState)($offer, OfferState::Garage, $by);
                }
            }
            // Счета — не здесь, а когда сделка пройдёт согласование с поставщиком (`AgreeDeal` из `EnterStage`, 09.10.2026);
            // маршрута нет — согласовывать не с кем, сразу.
            $offer->unsetRelation('positions');
            if (! $offer->position(Track::Sale)) {
                ($this->agree)($deal->fresh(), $by);
            }
            BidAccepted::dispatch($bid, $by);

            return $deal;
        });
    }
}
