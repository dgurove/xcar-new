<?php

namespace App\Offers\Actions;

use App\Garage\Actions\ReserveCar;
use App\Garage\GaragePayer;
use App\Offers\Bid;
use App\Offers\BidKind;
use App\Offers\BidState;
use App\Offers\CommissionMode;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\DealScheme;
use App\Offers\DealState;
use App\Offers\Events\BidAccepted;
use App\Offers\Events\BidDeclined;
use App\Offers\Giving;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferNumber;
use App\Offers\OfferState;
use App\Support\Money;
use App\Users\User;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Track;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Отдать» (18.10.2026, владелец: «договорились с Романом, что машина ему по такой-то цене, — а приходится публиковать
 * только ему и подтверждать за него») — одна дверь в сделку и в гараж: из черновика (в гараж — и без цены продажи), из
 * продажи и из сделки (отдать другому). Принять подтверждение — то же самое с готовым подтверждением.
 *
 * Сделка всегда стоит на подтверждении: нет его — заводится от имени сотрудника (`PlaceBid::record`, `placed_by`), так
 * история, страница предложения у менеджера на сайте, резерв и отмена работают одинаково. Исключение одно — гараж без
 * менеджера («взяли под себя», платим мы). Остальные подтверждения не отклоняются — это резерв: выбранный передумал, и
 * машину отдают другому тем же действием; прежняя сделка тогда отменяется, этап остаётся, его просьба — новому.
 *
 * Деньги фиксируются тут же: закупочная — какой её видел менеджер, подтверждая (`bids.cost`, закреплена навсегда),
 * вознаграждение и режим — менеджер их не увидит до счёта. Схема оплаты (`DealScheme`): ДКП и «страховой напрямую» —
 * сколько покупатель отдаёт не нам, вознаграждение он удерживает сам; гаражная «платит менеджер» — наша доля по ссылке.
 * Счета — после согласования с поставщиком (`AgreeDeal` из `EnterStage`), без маршрута — сразу.
 *
 * Гаражная — сделка без цены и вознаграждения, но с тем, кто платит поставщику: машина сразу в гараже «Ждёт машину»
 * (`ReserveCar`), привезёт её вывоз. Маршрут — с входа сделки (`Workflow::dealEntry`): у открытого — исходом приёма, у
 * черновика — прямо туда; черновик получает настоящий номер, как при публикации.
 */
final class Give
{
    public function __construct(private ChangeOfferState $changeState, private CancelDeal $cancelDeal, private EnterStage $enter,
        private ReserveCar $reserve, private AgreeDeal $agree, private AssignDealPickup $pickup) {}

    public function __invoke(Giving $g, User $by): Deal
    {
        return DB::transaction(function () use ($g, $by) {
            $offer = Offer::whereKey($g->offer->id)->lockForUpdate()->firstOrFail();
            if (! in_array($offer->state, [OfferState::Draft, OfferState::Gallery, OfferState::Open, OfferState::Sold], true)) {
                throw ValidationException::withMessages(['give' => 'Отдать можно из черновика, продажи или сделки']);
            }
            $bid = $g->bid ? $this->activeBid($g->bid, $offer) : null;
            $manager = $bid?->user ?? $g->manager;
            $garage = $bid ? $bid->isGarage() : $g->garage;
            $this->check($offer, $manager, $garage, $bid ? $bid->amount : $g->amount, $bid !== null);
            $this->checkBuyer($offer, $manager, $garage, $g->buyerPrice);

            $bid ??= $manager ? PlaceBid::record($offer, $manager, $by, $garage ? null : $g->amount, null, $garage ? BidKind::Garage : BidKind::Buyer) : null;
            // Закупочная менеджера — какой она была, когда он подтверждал (15.10.2026); нынешняя, если другая, — закупочная
            // поставщика, только админу.
            $cost = $bid?->frozenCost() ?? $offer->bidFloor();
            // Не выше закупочной — только с согласия (14.10.2026: сделка 3308 ушла в минус молча). Закупочную могли поднять
            // после того, как открыли шторку, — тогда переспрашиваем.
            if (! $garage && ! $g->loss && $cost && $bid->amount <= $cost) {
                throw ValidationException::withMessages(['give' => 'Цена '.Money::rub($bid->amount).' не выше закупочной '.Money::rub($cost).': отдайте ещё раз']);
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
            $bid?->update(['state' => BidState::Accepted, 'decided_at' => now(), 'decided_by' => $by->id]);

            $deal = $this->deal($offer, $g, $bid, $manager, $garage, $cost);
            $offer->log(OfferEventType::BidAccepted, $by, array_filter(['bid_id' => $bid?->id, 'amount' => $deal->amount, 'commission' => $deal->commission,
                'mode' => $deal->commission_mode?->value, 'garage' => $deal->garage_payer?->value, 'manager' => $bid ? null : $manager?->id,
                'scheme' => $deal->scheme->value, 'dkp' => $deal->ownerPrice(), 'share' => $deal->share,
                'buyer_price' => $garage && $manager?->holdsAsBuyer() ? $g->buyerPrice : null], fn ($v) => $v !== null));
            $deal->setRelation('offer', $offer);
            // Покупатель по договору у супер-покупателя — он сам, выбирать некого; вознаграждения у его сделки нет — его
            // снимает сама сделка (`Deal::booted`).
            if ($deal->isDirect()) {
                DealContract::for($deal);
            }
            if ($garage) {
                ($this->reserve)($deal, $by, $manager?->holdsAsBuyer() ? $g->buyerPrice : null);
            }

            $offer = $previous && $offer->state === OfferState::Sold ? $this->handOver($offer, $previous, $deal, $by) : $this->enterDeal($offer, $deal, $by);
            $offer->unsetRelation('positions');
            if (! $offer->position(Track::Sale)) {
                // Маршрута нет — согласовывать не с кем и ждать со страховой нечего: счета сразу, гаражная — сразу «В гараже».
                ($this->agree)($deal->fresh(), $by);
                if ($garage) {
                    ($this->changeState)($offer->fresh(), OfferState::Garage, $by);
                }
            }
            if ($g->pickup) {
                ($this->pickup)($offer, $deal->fresh(), $g->pickup, $by, $g->where, $previous);
            }
            // Покупателю с гаражом у Совкомбанка счёт на его цену — сразу (`SyncDealInvoices`): ждать «да» там нечего.
            if ($garage && $manager?->holdsAsBuyer() && $deal->isCommission()) {
                app(SyncDealInvoices::class)($deal->fresh(), $by);
            }
            if ($bid) {
                BidAccepted::dispatch($bid, $by);
            }

            return $deal;
        });
    }

    private function activeBid(Bid $bid, Offer $offer): Bid
    {
        $bid = Bid::whereKey($bid->id)->lockForUpdate()->firstOrFail();
        if ($bid->state !== BidState::Active || $bid->offer_id !== $offer->id) {
            throw ValidationException::withMessages(['give' => 'Подтверждение уже '.mb_strtolower($bid->state->label())]);
        }
        $bid->setRelation('offer', $offer);

        return $bid;
    }

    /** Кому и за сколько можно отдать. Готовое подтверждение уже прошло свои проверки — его не переспрашиваем. */
    private function check(Offer $offer, ?User $manager, bool $garage, ?int $amount, bool $hasBid): void
    {
        if ($hasBid) {
            return;
        }
        if ($garage) {
            if ($manager && ! $manager->isManager() && ! $manager->garageBuyer()) {
                throw ValidationException::withMessages(['manager_id' => 'В гараж ТС берёт менеджер или покупатель с гаражом']);
            }

            return;
        }
        $errors = array_filter([
            'manager_id' => ! $manager ? 'Кому отдать' : (! $manager->canBid() ? 'В сделку отдают менеджеру' : null),
            'give' => ! $offer->asking_price ? 'Сначала цена продажи' : null,
            'amount' => ! $amount || $amount < 1 ? 'Цена менеджеру' : null,
        ]);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Покупателю в гараж (20.10.2026): только с его ценой и где поставщику платим мы — и супер-покупателю, что сам нажал
     * «В гараж» (его подтверждение без суммы, цену вписывает админ при «Принять»).
     */
    private function checkBuyer(Offer $offer, ?User $holder, bool $garage, ?int $price): void
    {
        if (! $garage || ! $holder?->holdsAsBuyer()) {
            return;
        }
        if (! $holder->garageBuyer()) {
            throw ValidationException::withMessages(['manager_id' => 'Гараж покупателю не включён']);
        }
        if (! $offer->garageToBuyer()) {
            throw ValidationException::withMessages(['give' => 'У этого поставщика покупателю в гараж не отдать: платить поставщику некому']);
        }
        if (! $price || $price < 1) {
            throw ValidationException::withMessages(['buyer_price' => 'Цена для покупателя']);
        }
    }

    private function deal(Offer $offer, Giving $g, ?Bid $bid, ?User $manager, bool $garage, ?int $cost): Deal
    {
        $scheme = DealScheme::resolve($offer, $g->scheme);
        [$commission, $mode, $payer, $share] = [$g->commission, $g->mode, null, null];
        if ($garage) {
            // Без менеджера — «взяли под себя»: поставщику платим мы. Совкомбанк (менеджер платит ПРАЙМ по счёту) и маршрут
            // без гаражной ветки (Т-Страхование) — платит менеджер, путём обычной сделки (`Offer::garagePayerFixed`).
            // Покупателю — всегда мы: расчёта у него нет, он платит свою цену счётом ПРАЙМ (20.10.2026).
            $payer = ! $manager || $manager->holdsAsBuyer() ? GaragePayer::Us : ($offer->garagePayerFixed() ?? $g->payer ?? GaragePayer::Us);
            [$commission, $mode] = [null, CommissionMode::Payout];
            // Наша доля — только у «платит менеджер»: он платит её по ссылке сразу (05.10.2026). Совкомбанк: доли нет —
            // машина уходит без оплаты, нашу часть даёт продажа (`Deal::namesBuyer`).
            $share = $payer === GaragePayer::Manager && ! $offer->vendor?->isCommission() ? $g->share : null;
        }
        // Покупатель платит не нам (ДКП, страховой) — вознаграждение остаётся у менеджера, нам он платит подбор.
        if ($scheme->paysSelection()) {
            $mode = CommissionMode::Withheld;
        }

        return Deal::create(['offer_id' => $offer->id, 'bid_id' => $bid?->id, 'buyer_id' => $manager?->id, 'amount' => $bid?->amount, 'state' => DealState::Active,
            'cost' => $cost, 'supplier_cost' => $offer->floor_price && $offer->floor_price !== $cost ? $offer->floor_price : null,
            'owner_price' => $scheme->paysSelection() && ! $garage ? ($g->ownerPrice ?? $offer->owner_price ?? $cost) : null,
            'share' => $share, 'commission' => $commission, 'commission_mode' => $mode, 'scheme' => $scheme, 'garage_payer' => $payer]);
    }

    /**
     * Отдали другому: этап тот же, просьба к менеджеру — новому (вход на этап заводит её по сделке). Сменилась ветка (гараж
     * «платим мы» ↔ остальные) — с начала сделки: этап чужой ветки этой сделке не подходит.
     */
    private function handOver(Offer $offer, Deal $previous, Deal $deal, User $by): Offer
    {
        $stage = $offer->stage();
        if ($stage && $previous->isGarageUs() !== $deal->isGarageUs()) {
            $stage = $stage->workflow->dealEntry($deal) ?? $stage;
        }

        return $stage ? ($this->enter)($offer, $stage, $by) : $offer;
    }

    /**
     * В сделку: черновик получает настоящий номер (менеджер видит его, а не временный), состояние — «Идёт сделка».
     * Открытое идёт исходом приёма своего этапа, как раньше принятие; не сдвинулся маршрут (черновик, этап без такого
     * исхода) или его не было — встаёт на вход сделки своего маршрута (`dealEntry`), ветка которого подходит этой сделке.
     */
    private function enterDeal(Offer $offer, Deal $deal, User $by): Offer
    {
        $open = $offer->state === OfferState::Open;
        if (! $open) {
            OfferNumber::reserve($offer);
        }
        $before = $offer->position(Track::Sale)?->stage_id;
        $offer = ($this->changeState)($offer, OfferState::Sold, $by, followRoute: $open);
        $offer->unsetRelation('positions');
        $position = $offer->position(Track::Sale);
        if ($position && $position->stage_id !== $before) {
            return $offer;
        }
        $workflow = $position ? $position->stage->workflow : ($offer->vendor?->is_active ? $offer->vendor->workflow(Track::Sale) : null);
        $entry = $position || $workflow?->is_active ? $workflow?->dealEntry($deal) : null;

        return $entry && $entry->id !== $position?->stage_id ? ($this->enter)($offer, $entry, $by) : $offer;
    }
}
