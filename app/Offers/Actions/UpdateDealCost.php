<?php

namespace App\Offers\Actions;

use App\Billing\Robot;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\OfferEventType;
use App\Support\Changes;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Закупочная в идущей сделке (14.10.2026, владелец после файла Carcade: «новая цена везде»). Сделка держит снимок
 * закупочной с принятия (`AcceptBid`), но меняет его только эта дверь — её зовёт `UpdateOffer`, когда правят закупочную
 * предложения с идущей сделкой. Сумма собственнику по ДКП, равная прежней закупочной, идёт следом; договор и счета —
 * по новым деньгам (`SyncDealInvoices`). По счетам сделки уже платили — менять нельзя.
 */
final class UpdateDealCost
{
    public function __construct(private SyncDealInvoices $invoices) {}

    /** Проверка до записи предложения: правка не встанет на полпути. */
    public static function guard(Deal $deal): void
    {
        if (! $deal->moneyEditable()) {
            throw ValidationException::withMessages(['floor_price' => 'По счёту сделки уже платили: снимите «И в сделке», чтобы поменять только у предложения']);
        }
    }

    public function __invoke(Deal $deal, int $cost, ?User $by): void
    {
        if ($deal->cost === $cost) {
            return;
        }
        self::guard($deal);
        DB::transaction(function () use ($deal, $cost, $by) {
            $was = $deal->cost;
            $deal->cost = $cost;
            if ($deal->owner_price !== null && $deal->owner_price === $was) {
                $deal->owner_price = $cost;
            }
            $diff = Changes::of($deal);
            $deal->save();
            $deal->offer->log(OfferEventType::Updated, $by, ['fields' => array_map(fn ($f) => $f === 'owner_price' ? 'deal_owner_price' : $f, $diff['fields']),
                'changes' => collect($diff['changes'])->mapWithKeys(fn ($v, $f) => [$f === 'owner_price' ? 'deal_owner_price' : $f => $v])->all()]);
            $deal->contract?->update(['price' => DealContract::priceFor($deal)]);
            ($this->invoices)($deal->fresh(), $by ?? Robot::user());
        });
    }
}
