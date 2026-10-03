<?php

namespace App\Billing\Bank\Actions;

use App\Billing\Acquiring\AcquiringPayment;
use App\Billing\Acquiring\Gateway;
use App\Billing\Bank\Transaction;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Перечисление НКО «ЮМани» на расчётный счёт — уже учтённые оплаты по ссылкам, счёта оно не закрывает.
 * ЮKassa платит следующим рабочим днём за вычетом комиссии, а реестра «что вошло в поручение» по API нет,
 * поэтому сверяем суммой: успешные оплаты без перечисления за неделю до поступления, по времени оплаты,
 * набираются по `income_amount` до ровно суммы поступления. Сошлось — `matched` и оплатам проставлено
 * поступление; нет (возврат вычли, оплату провели руками) — `unmatched`, решит человек, утренняя сводка его покажет.
 */
final class ReconcilePayout
{
    private const DAYS = 7;

    public function __construct(private Gateway $gateway) {}

    public function __invoke(Transaction $tx): Transaction
    {
        return DB::transaction(function () use ($tx) {
            $tx = Transaction::whereKey($tx->id)->lockForUpdate()->firstOrFail();
            if ($tx->state === Transaction::MATCHED) {
                return $tx;
            }
            $open = $this->open($tx->booked_at);
            $sum = 0.0;
            foreach ($open as $n => $attempt) {
                $sum = round($sum + $attempt->income_amount, 2);
                if (abs($sum - $tx->amount) < 0.005) {
                    $taken = $open->take($n + 1);
                    AcquiringPayment::whereIn('id', $taken->pluck('id'))->update(['payout_tx_id' => $tx->id]);
                    $fee = round($taken->sum('amount') - $sum, 2);
                    $tx->update(['state' => Transaction::MATCHED, 'note' => 'Эквайринг: оплат '.$taken->count().', комиссия '.Money::exact($fee), 'decided_at' => now()]);

                    return $tx;
                }
                if ($sum > $tx->amount) {
                    break;
                }
            }
            $expected = round($open->sum('income_amount'), 2);
            $tx->update(['state' => Transaction::UNMATCHED, 'note' => 'Эквайринг не сошёлся'.($expected > 0 ? ' на '.Money::exact(abs($expected - $tx->amount)) : ': оплат по ссылкам нет')]);

            return $tx;
        });
    }

    /** Успешные оплаты, ещё не нашедшие своего перечисления, — по времени оплаты; комиссию без неё дочитываем у провайдера. */
    private function open(Carbon $booked): Collection
    {
        return AcquiringPayment::where('status', 'succeeded')->whereNull('payout_tx_id')
            ->where('created_at', '>=', $booked->copy()->subDays(self::DAYS)->startOfDay())->where('created_at', '<', $booked->copy()->startOfDay())
            ->get()
            ->each(function (AcquiringPayment $a) {
                if ($a->income_amount === null) {
                    try {
                        $a->update(['income_amount' => $this->gateway->fetch($a->external_id)->income]);
                    } catch (Throwable) {
                        // Провайдер молчит — оплата пойдёт в сверку без комиссии и, скорее всего, не сойдётся: решит человек.
                    }
                }
            })
            ->filter(fn (AcquiringPayment $a) => $a->income_amount !== null)
            ->sortBy(fn (AcquiringPayment $a) => Carbon::parse(data_get($a->payload, 'captured_at') ?? $a->created_at)->getTimestamp())
            ->values();
    }

    /** Оплаты, что вошли в это перечисление, — для карточки поступления. */
    public static function payments(Transaction $tx): Collection
    {
        return AcquiringPayment::where('payout_tx_id', $tx->id)->with('link.invoice.party')->orderBy('created_at')->get();
    }
}
