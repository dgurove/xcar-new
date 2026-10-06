<?php

namespace App\Garage\Actions;

use App\Garage\Car;
use App\Garage\GaragePayer;
use App\Notifications\BidAcceptedNotice;
use App\Offers\Actions\ChangeOfferState;
use App\Offers\Actions\DeclineBid;
use App\Offers\Actions\SyncDealInvoices;
use App\Offers\Bid;
use App\Offers\BidState;
use App\Offers\Deal;
use App\Offers\DealScheme;
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
 * «Отдать в гараж» из CRM — как принятое гаражное подтверждение, только без него (06.10.2026: одна дверь; отданная
 * менеджеру — «в гараже», как в сделке, не черновик). Заводится гаражная сделка, предложение встаёт на маршрут с
 * начала сделки, машина — в гараж, вывоз везёт её к менеджеру (без менеджера — «взяли под себя», платим мы, к нам).
 * Где машина: `owner` — у страховой, `collect` — можно забирать, `arrived` — уже у менеджера (подготовка сразу).
 * Отданную так до 06.10.2026 («В гараже» без сделки) тоже ведёт сюда: строка гаража получает сделку.
 */
final class GiveToGarage
{
    public const WHERE = ['owner' => 'У страховой', 'collect' => 'Можно забирать', 'arrived' => 'Уже у менеджера'];

    public function __construct(private ChangeOfferState $state, private EnterStage $enter, private StartRoute $start, private ReserveCar $reserve, private EnsurePickup $pickup) {}

    public function __invoke(Offer $offer, ?User $manager, GaragePayer $payer, User $by, string $where = 'owner', User|false|null $evacuator = false): Car
    {
        if ($manager && ! $manager->isManager()) {
            throw ValidationException::withMessages(['manager_id' => 'В гараж ТС берёт менеджер']);
        }
        $legacy = $offer->state === OfferState::Garage && ! Car::where('offer_id', $offer->id)->whereNotNull('deal_id')->exists();
        if (! $legacy && ! in_array($offer->state, [OfferState::Draft, OfferState::Gallery, OfferState::Open], true)) {
            throw ValidationException::withMessages(['state' => 'В гараж — из черновика или продажи']);
        }
        // Без менеджера — «взяли под себя»: поставщику платим мы. Без гаражной ветки маршрута (Т-Страхование) — платит менеджер.
        $payer = ! $manager ? GaragePayer::Us : ($offer->garageBranch() ? $payer : GaragePayer::Manager);

        return DB::transaction(function () use ($offer, $manager, $payer, $by, $where, $evacuator, $legacy) {
            $offer->bids()->where('state', BidState::Active)->get()->each(fn (Bid $bid) => app(DeclineBid::class)($bid, $by));
            $deal = Deal::create(['offer_id' => $offer->id, 'buyer_id' => $manager?->id, 'state' => DealState::Active, 'cost' => $offer->floor_price, 'garage_payer' => $payer,
                'scheme' => DealScheme::forVendor($offer->vendor?->deal_format)]);
            $offer->log(OfferEventType::BidAccepted, $by, array_filter(['garage' => $payer->value, 'manager' => $manager?->id]));
            // Счета, что известны уже сейчас (у «платит менеджер» по ПРАЙМ — машина на закупочную), — как у принятия.
            app(SyncDealInvoices::class)($deal->setRelation('offer', $offer), $by);
            $car = ($this->reserve)($deal, $by);

            if ($legacy) {
                // Предложение уже «В гараже»: назад в сделку только так — переход Garage → Sold дверью состояний закрыт.
                $offer->forceFill(['state' => OfferState::Sold])->save();
                $offer->log(OfferEventType::StateChanged, $by, ['from' => OfferState::Garage->value, 'to' => OfferState::Sold->value]);
            } else {
                $offer = ($this->state)($offer, OfferState::Sold, $by, followRoute: false);
            }
            // Маршрут — с начала сделки: куда ведёт принятие (`Workflow::dealEntry`). Не стоял на маршруте — ставим (StartRoute
            // встаёт на этап своего состояния); маршрута нет вовсе — ждать со страховой нечего.
            $position = $offer->fresh()->position(Track::Sale);
            if ($position && ($entry = $position->stage->workflow->dealEntry($deal))) {
                $offer = ($this->enter)($offer, $entry, $by);
            } elseif (! $position) {
                $offer = ($this->start)($offer, $by, Track::Sale);
            }

            ($this->pickup)($offer, $by, $evacuator === false ? $manager : $evacuator, $where);
            if (! $offer->fresh()->position(Track::Sale)) {
                ($this->state)($offer->fresh(), OfferState::Garage, $by);
            }
            $manager?->notify(new BidAcceptedNotice($deal->load('offer')));

            return $car->refresh();
        });
    }
}
