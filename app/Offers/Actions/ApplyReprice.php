<?php

namespace App\Offers\Actions;

use App\Notifications\RepriceAskNotice;
use App\Offers\Bid;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\Reprice;
use App\Offers\RepriceAsk;
use App\Support\Money;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Применить переоценку (28.10.2026, владелец: «при переоценке спрашивать у тех, кто сейчас подтвердил, и в сделке —
 * оставить прежнюю цену закупа или обновить; это важно технически верно реализовать»). Под замком предложения, одной
 * транзакцией:
 * - закупочная — `UpdateOffer` (`repricing`), с причиной в истории; заодно заявленная и цена продажи из шторки;
 * - подтверждение: «Обновить» вниз — `bids.cost` = новая молча; вверх — вопрос менеджеру (`RepriceAsk`, 28.10.2026,
 *   владелец: «ставим перед фактом — неправильно»), цена встанет, когда он согласится (`AnswerReprice`); не видит разницы
 *   (заявленная вписана рукой, супер-покупатель) — встаёт сразу. «Оставить» — как было, молча (у старого подтверждения без
 *   своей закупочной она закрепляется прежней);
 * - сделка: «Обновить» — `deals.cost` = новая (у ДКП и «страховой напрямую» сумма страховой тоже, если стояла равной
 *   прежней), договор и неоплаченные счета перевыставляются (`SyncDealInvoices`) — вниз сразу, вверх — после его «Согласен»;
 *   по счёту уже платили — только «Оставить». «Оставить» (и пока ждём ответа) — новая ложится в `supplier_cost`
 *   (`UpdateDealCost`, как правка руками).
 * Решение обязательно по каждому: без него — ошибка, ничего не меняется.
 */
final class ApplyReprice
{
    public const KEEP = 'keep';

    public const UPDATE = 'update';

    public function __construct(private UpdateOffer $update, private SyncDealInvoices $invoices) {}

    /**
     * @param  array<int|string, string>  $bids  id подтверждения → keep | update
     * @param  array{publish_price?: ?int, asking_price?: ?int}  $prices
     */
    public function __invoke(Reprice $reprice, User $by, int $price, array $bids, ?string $deal, array $prices = []): void
    {
        $notify = [];
        DB::transaction(function () use ($reprice, $by, $price, $bids, $deal, $prices, &$notify) {
            $reprice = Reprice::whereKey($reprice->id)->lockForUpdate()->firstOrFail();
            $offer = Offer::whereKey($reprice->offer_id)->lockForUpdate()->firstOrFail();
            if (! $reprice->isPending()) {
                throw ValidationException::withMessages(['reprice' => 'Эту переоценку уже разобрали']);
            }
            ['bids' => $live, 'deal' => $liveDeal] = Reprice::affected($offer);
            $choices = $this->choices($live->all(), $bids, $liveDeal, $deal);

            $from = (int) $offer->floor_price;
            // Закупочная каждого — до записи новой: у старых подтверждений без своей (`bids.cost` пуст, до 15.10.2026) она
            // бралась от предложения, и «Оставить» после записи отдало бы им новую.
            $was = $live->mapWithKeys(fn (Bid $bid) => [$bid->id => (int) ($bid->cost ?? $from)]);
            // Оценочная из письма (Альфа) — вместе с закупочной, если её не поправили в шторке.
            $value = $reprice->value !== null && $price === $reprice->to_price ? ['value' => $reprice->value] : [];
            $data = ['floor_price' => $price] + $value + array_intersect_key($prices, array_flip(['publish_price', 'asking_price']));
            ($this->update)($offer, $data, $by, ['why' => $reprice->why()], repricing: true);

            // Прежние вопросы по этим подтверждениям и сделке гаснут: решается заново по новой переоценке.
            RepriceAsk::pending()->where('offer_id', $offer->id)->update(['state' => RepriceAsk::VOID]);
            $people = [];
            foreach ($live as $bid) {
                if ($choices['bids'][$bid->id] === self::UPDATE && $was[$bid->id] !== $price) {
                    if ($ask = $this->ask($reprice, $offer, $bid, $was[$bid->id], $price)) {
                        $notify[] = $ask;
                        $people[] = $bid->user->shortName().' — спросили: '.Money::plain($was[$bid->id], sign: false).' → '.Money::plain($price);
                    } else {
                        $bid->update(['cost' => $price]);
                        $people[] = $bid->user->shortName().' — '.Money::plain($was[$bid->id], sign: false).' → '.Money::plain($price);
                    }
                } else {
                    $bid->cost === null && $bid->update(['cost' => $was[$bid->id]]);
                    $people[] = $bid->user->shortName().' — оставлена '.Money::plain($was[$bid->id]);
                }
            }
            if ($liveDeal) {
                $liveDeal = $liveDeal->fresh();
                $dealWas = (int) $liveDeal->cost;
                if ($choices['deal'] === self::UPDATE && $dealWas !== $price) {
                    if ($ask = $this->ask($reprice, $offer, $liveDeal, $dealWas, $price)) {
                        $notify[] = $ask;
                        $people[] = 'сделка '.($liveDeal->buyer?->shortName() ?? '').' — спросили: '.Money::plain($dealWas, sign: false).' → '.Money::plain($price);
                    } else {
                        $this->updateDeal($liveDeal, $price, $by);
                        $people[] = 'сделка '.($liveDeal->buyer?->shortName() ?? '').' — '.Money::plain($dealWas, sign: false).' → '.Money::plain($price);
                    }
                } else {
                    $people[] = 'сделка '.($liveDeal->buyer?->shortName() ?? '').' — оставлена '.Money::plain($dealWas);
                }
            }

            $reprice->update(['state' => Reprice::APPLIED, 'to_price' => $price, 'choices' => $choices, 'decided_by' => $by->id, 'decided_at' => now()]);
            $offer->log(OfferEventType::Reprice, $by, ['reprice' => $reprice->id, 'state' => Reprice::APPLIED, 'from' => $from, 'to' => $price, 'people' => $people]);
        });
        foreach ($notify as $ask) {
            $ask->user->notify(new RepriceAskNotice($ask->load(['offer', 'deal'])));
        }
    }

    /**
     * Подтверждение с открытым вопросом о закупочной приняли (`Give`) — вопрос переезжает на сделку: что менеджер видит,
     * считается заново (`RepriceAsk::shownFor` сделки); не видит — новая встаёт сразу, как при «Обновить» без вопроса.
     */
    public function carryToDeal(Bid $bid, Deal $deal, User $by, bool $costGiven): void
    {
        $ask = RepriceAsk::pending()->where('bid_id', $bid->id)->latest('id')->first();
        if (! $ask) {
            return;
        }
        if ($costGiven || (int) $deal->cost >= $ask->to_price) {
            $ask->update(['state' => RepriceAsk::VOID]);

            return;
        }
        $shown = RepriceAsk::shownFor($deal->buyer, $deal, $deal->offer, (int) $deal->cost, $ask->to_price);
        if ($shown === null) {
            $ask->update(['state' => RepriceAsk::VOID]);
            $this->updateDeal($deal, $ask->to_price, $by);

            return;
        }
        $ask->update(['bid_id' => null, 'deal_id' => $deal->id, 'from_price' => (int) $deal->cost, 'shown_from' => $shown[0], 'shown_to' => $shown[1]]);
    }

    /**
     * Закупочная выросла — вопрос тому, кто подтвердил или ведёт сделку; null — цена встаёт сразу: снизилась, или он
     * разницы не видит (`RepriceAsk::shownFor`).
     */
    private function ask(Reprice $reprice, Offer $offer, Bid|Deal $on, int $from, int $to): ?RepriceAsk
    {
        $user = $on instanceof Bid ? $on->user : $on->buyer;
        $shown = $to > $from ? RepriceAsk::shownFor($user, $on, $offer, $from, $to) : null;
        if ($shown === null) {
            return null;
        }

        return RepriceAsk::create([
            'reprice_id' => $reprice->id, 'offer_id' => $offer->id, 'user_id' => $user->id,
            'bid_id' => $on instanceof Bid ? $on->id : null, 'deal_id' => $on instanceof Deal ? $on->id : null,
            'from_price' => $from, 'to_price' => $to, 'shown_from' => $shown[0], 'shown_to' => $shown[1],
            'state' => RepriceAsk::PENDING, 'due_at' => RepriceAsk::dueFrom(now()),
        ])->setRelation('user', $user);
    }

    /**
     * Решение по каждому из тех, кто сейчас подтвердил, и по сделке; кто подтвердил, пока шторка была открыта, — тоже должен
     * быть в решении, иначе отказ: админ его не видел.
     *
     * @param  list<Bid>  $live
     * @return array{bids: array<int, string>, deal: ?string}
     */
    private function choices(array $live, array $bids, ?Deal $liveDeal, ?string $deal): array
    {
        $out = ['bids' => [], 'deal' => null];
        foreach ($live as $bid) {
            $choice = $bids[$bid->id] ?? $bids[(string) $bid->id] ?? null;
            if (! in_array($choice, [self::KEEP, self::UPDATE], true)) {
                throw ValidationException::withMessages(['reprice' => 'Решите по каждому, кто подтвердил: '.$bid->user->shortName()]);
            }
            $out['bids'][$bid->id] = $choice;
        }
        if ($liveDeal) {
            if (! in_array($deal, [self::KEEP, self::UPDATE], true)) {
                throw ValidationException::withMessages(['reprice' => 'Решите по сделке: оставить прежнюю закупочную или обновить']);
            }
            if ($deal === self::UPDATE && ! $liveDeal->moneyEditable()) {
                throw ValidationException::withMessages(['reprice' => 'По счёту сделки уже платили — закупочную в ней можно только оставить']);
            }
            $out['deal'] = $deal;
        }

        return $out;
    }

    /**
     * Сделка с новой закупочной: `cost`, сумма страховой у ДКП и «страховой напрямую» (если стояла равной прежней — значит,
     * её никто не вписывал отдельно), договор и счета. `supplier_cost` гаснет — закупочная поставщика и менеджера снова одна.
     */
    public function updateDeal(Deal $deal, int $price, User $by): void
    {
        $was = (int) $deal->cost;
        $deal->forceFill([
            'cost' => $price,
            'supplier_cost' => null,
            'owner_price' => $deal->owner_price !== null && (int) $deal->owner_price === $was ? $price : $deal->owner_price,
        ])->save();
        $fresh = $deal->fresh();
        $fresh->contract?->update(['price' => DealContract::priceFor($fresh)]);
        ($this->invoices)($fresh, $by);
    }
}
