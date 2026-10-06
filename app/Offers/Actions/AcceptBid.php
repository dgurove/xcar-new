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
 * Схема оплаты (05.10.2026, `DealScheme`): ДКП и «страховой напрямую» — сколько покупатель отдаёт не нам (`owner_price`;
 * меньше закупочной — взаимозачёт), вознаграждение менеджер удерживает сам, счёт «Подбор ТС» со ссылкой — сразу;
 * ПРАЙМ — счёт плательщику, когда менеджер его укажет. Гаражная «платит менеджер» — наша доля (`share`) сразу по ссылке,
 * у ПРАЙМ ещё счёт за машину на закупочную (`SyncDealInvoices`).
 *
 * Гаражное подтверждение («В гараж») — та же сделка без цены и вознаграждения, но с тем, кто платит поставщику:
 * маршрут вендора ведёт её как обычную (или гаражной веткой, когда платим мы), машина сразу встаёт менеджеру в
 * гараж «Ждёт машину»: привезёт её вывоз (`EnsurePickup` — в контроллере, вместе с выбором, кто везёт).
 */
final class AcceptBid
{
    public function __construct(private ChangeOfferState $changeState, private CancelDeal $cancelDeal, private EnterStage $enterStage, private ReserveCar $reserve, private SyncDealInvoices $invoices) {}

    public function __invoke(Bid $bid, User $by, ?int $commission = null, CommissionMode $mode = CommissionMode::Payout, ?GaragePayer $payer = null, DealScheme $scheme = DealScheme::Prime, ?int $ownerPrice = null, ?int $share = null): Deal
    {
        return DB::transaction(function () use ($bid, $by, $commission, $mode, $payer, $scheme, $ownerPrice, $share) {
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
                [$commission, $mode] = [null, CommissionMode::Payout];
                // Наша доля — только у «платит менеджер»: он платит её по ссылке сразу (05.10.2026).
                $share = $payer === GaragePayer::Manager ? $share : null;
            } else {
                [$payer, $share] = [null, null];
            }
            // Покупатель платит не нам (ДКП, страховой) — вознаграждение остаётся у менеджера, нам он платит подбор.
            if ($scheme->paysSelection()) {
                $mode = CommissionMode::Withheld;
            }
            $deal = Deal::create(['offer_id' => $offer->id, 'bid_id' => $bid->id, 'buyer_id' => $bid->user_id, 'amount' => $bid->amount, 'state' => DealState::Active,
                'cost' => $offer->floor_price, 'owner_price' => $scheme->paysSelection() && ! $bid->isGarage() ? ($ownerPrice ?? $offer->owner_price ?? $offer->floor_price) : null,
                'share' => $share, 'commission' => $commission, 'commission_mode' => $mode, 'scheme' => $scheme, 'garage_payer' => $payer]);
            $offer->log(OfferEventType::BidAccepted, $by, array_filter(['bid_id' => $bid->id, 'amount' => $bid->amount, 'commission' => $commission, 'mode' => $mode->value, 'garage' => $payer?->value,
                'scheme' => $scheme->value, 'dkp' => $deal->ownerPrice(), 'share' => $share], fn ($v) => $v !== null));
            // Счета, что известны уже сейчас (подбор, гаражная доля и машина), — сразу; ПРАЙМ — когда менеджер укажет плательщика.
            ($this->invoices)($deal->setRelation('offer', $offer), $by);
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
            BidAccepted::dispatch($bid, $by);

            return $deal;
        });
    }
}
