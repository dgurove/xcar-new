<?php

namespace App\Billing\Listeners;

use App\Billing\Events\PaymentRecorded;
use App\Billing\Events\PaymentVoided;
use App\Offers\Actions\StampDealMoney;
use App\Offers\Deal;

/**
 * «Продано» по оплате (`DealDates`, 14.11.2026; с того же дня — первый платёж, а не «всё оплачено»: деньги за машину чаще
 * лежат зачётом `credit_deal_id`, а счёта за ТС у сделки нет): оплату записали или отменили — пересчёт `StampDealMoney`.
 */
final class StampPaidIn
{
    public function __construct(private StampDealMoney $stamp) {}

    public function handle(PaymentRecorded|PaymentVoided $e): void
    {
        $invoice = $e instanceof PaymentRecorded ? $e->invoice : $e->payment->invoice;
        if (! $invoice || $invoice->direction !== 'issued') {
            return;
        }
        foreach (array_filter(array_unique([$invoice->deal_id, $invoice->credit_deal_id])) as $id) {
            if ($deal = Deal::find($id)) {
                // Только «Продано»: шаг поставщика из записи оплаты не двигаем (он — от выписки и `bank:link`).
                ($this->stamp)($deal, supplier: false);
            }
        }
    }
}
