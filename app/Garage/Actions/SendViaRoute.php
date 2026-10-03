<?php

namespace App\Garage\Actions;

use App\Garage\Car;
use App\Garage\GaragePayer;
use App\Notifications\BidAcceptedNotice;
use App\Offers\Actions\ChangeOfferState;
use App\Offers\Actions\DeclineBid;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\OfferState;
use App\Users\User;
use App\Workflow\Actions\EnterStage;
use App\Workflow\Actions\StartRoute;
use App\Workflow\Track;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Отдать в гараж» из CRM с этапа «Ждёт страховую»: как принятое гаражное подтверждение, только без него. Заводится
 * гаражная сделка менеджеру, предложение встаёт на маршрут с начала сделки, машина — менеджеру в гараж.
 */
final class SendViaRoute
{
    public function __construct(private ChangeOfferState $state, private EnterStage $enter, private StartRoute $start, private ReserveCar $reserve) {}

    public function __invoke(Offer $offer, User $manager, GaragePayer $payer, User $by): Car
    {
        if (! $manager->isManager()) {
            throw ValidationException::withMessages(['manager_id' => 'В гараж ТС берёт менеджер']);
        }
        if (! in_array($offer->state, [OfferState::Draft, OfferState::Gallery, OfferState::Open], true)) {
            throw ValidationException::withMessages(['state' => 'В гараж через маршрут — из черновика или продажи']);
        }

        return DB::transaction(function () use ($offer, $manager, $payer, $by) {
            $offer->bids()->where('state', BidState::Active)->get()->each(fn (Bid $bid) => app(DeclineBid::class)($bid, $by));
            $deal = Deal::create(['offer_id' => $offer->id, 'buyer_id' => $manager->id, 'state' => DealState::Active, 'cost' => $offer->floor_price,
                'garage_payer' => $offer->garageBranch() ? $payer : GaragePayer::Manager]);
            $offer->log(OfferEventType::BidAccepted, $by, ['garage' => $deal->garage_payer->value, 'manager' => $manager->id]);
            $car = ($this->reserve)($deal, $by);

            $offer = ($this->state)($offer, OfferState::Sold, $by, followRoute: false);
            // Маршрут — с начала сделки: первый этап с «Идёт сделка». Не стоял на маршруте — ставим (StartRoute
            // встаёт на этап своего состояния); маршрута нет вовсе — ждать со страховой нечего, сразу на доставку.
            $position = $offer->position(Track::Sale);
            if ($position && ($entry = $position->stage->workflow->stages()->get()->first(fn ($s) => $s->offer_state === OfferState::Sold))) {
                $offer = ($this->enter)($offer, $entry, $by);
            } elseif (! $position) {
                $offer = ($this->start)($offer, $by, Track::Sale);
            }
            if (! $offer->position(Track::Sale)) {
                ($this->state)($offer, OfferState::Garage, $by);
            }
            $manager->notify(new BidAcceptedNotice($deal->load('offer')));

            return $car->refresh();
        });
    }
}
