<?php

namespace App\Offers\Actions;

use App\Notifications\CostChangedNotice;
use App\Offers\Bid;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Offers\Reprice;
use App\Support\Money;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Применить переоценку (28.10.2026, владелец: «при переоценке спрашивать у тех, кто сейчас подтвердил, и в сделке —
 * оставить прежнюю цену закупа или обновить; это важно технически верно реализовать»). Под замком предложения, одной
 * транзакцией:
 * - закупочная — `UpdateOffer` (`repricing`), с причиной в истории; заодно заявленная и цена продажи из шторки;
 * - подтверждение: «Обновить» — `bids.cost` = новая, менеджеру `CostChangedNotice`; «Оставить» — как было, молча;
 * - сделка: «Обновить» — `deals.cost` = новая (у ДКП и «страховой напрямую» сумма страховой тоже, если стояла равной
 *   прежней), договор и неоплаченные счета перевыставляются (`SyncDealInvoices`), менеджеру уведомление; по счёту уже
 *   платили — только «Оставить». «Оставить» — новая ложится в `supplier_cost` (`UpdateDealCost`, как правка руками).
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
            $data = ['floor_price' => $price] + array_intersect_key($prices, array_flip(['publish_price', 'asking_price']));
            ($this->update)($offer, $data, $by, ['why' => $reprice->why()], repricing: true);

            $people = [];
            foreach ($live as $bid) {
                $was = $bid->frozenCost();
                if ($choices['bids'][$bid->id] === self::UPDATE && $was !== $price) {
                    $bid->update(['cost' => $price]);
                    $notify[] = [$bid->user, new CostChangedNotice($bid, (int) $was, $price)];
                    $people[] = $bid->user->shortName().' — '.Money::plain((int) $was).' → '.Money::plain($price);
                } else {
                    $people[] = $bid->user->shortName().' — оставлена '.Money::plain((int) $was);
                }
            }
            if ($liveDeal) {
                $liveDeal = $liveDeal->fresh();
                $was = (int) $liveDeal->cost;
                if ($choices['deal'] === self::UPDATE && $was !== $price) {
                    $this->updateDeal($liveDeal, $price, $by);
                    $notify[] = [$liveDeal->buyer, new CostChangedNotice($liveDeal->fresh(), $was, $price)];
                    $people[] = 'сделка '.($liveDeal->buyer?->shortName() ?? '').' — '.Money::plain($was).' → '.Money::plain($price);
                } else {
                    $people[] = 'сделка '.($liveDeal->buyer?->shortName() ?? '').' — оставлена '.Money::plain($was);
                }
            }

            $reprice->update(['state' => Reprice::APPLIED, 'to_price' => $price, 'choices' => $choices, 'decided_by' => $by->id, 'decided_at' => now()]);
            $offer->log(OfferEventType::Reprice, $by, ['reprice' => $reprice->id, 'state' => Reprice::APPLIED, 'from' => $from, 'to' => $price, 'people' => $people]);
        });
        foreach ($notify as [$user, $notice]) {
            $user?->notify($notice);
        }
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
    private function updateDeal(Deal $deal, int $price, User $by): void
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
