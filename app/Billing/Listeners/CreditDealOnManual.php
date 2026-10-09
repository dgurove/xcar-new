<?php

namespace App\Billing\Listeners;

use App\Billing\Events\InvoiceVoided;
use App\Billing\Events\PaymentRecorded;
use App\Billing\Events\PaymentVoided;
use App\Billing\Invoice;
use App\Billing\Robot;
use App\Offers\Actions\SyncDealInvoices;
use App\Offers\Deal;
use App\Users\User;

/**
 * Деньги разовой ссылки, засчитанной в сделку (`credit_deal_id`), пришли, вернулись или счёт аннулирован — сделка
 * пересчитывает свои счета и зачёты (`SyncDealInvoices` → `ApplyDealCredits`). Шаг оплаты и выплату двигает уже оплата
 * её собственного счёта зачётом — штатными слушателями.
 */
final class CreditDealOnManual
{
    /** Пересчёт сам гасит и кладёт оплаты — их события сюда не возвращаются. */
    private static bool $running = false;

    public function __construct(private SyncDealInvoices $sync) {}

    public function handle(PaymentRecorded|PaymentVoided|InvoiceVoided $e): void
    {
        $invoice = $e instanceof PaymentVoided ? $e->payment->invoice : $e->invoice;
        if (! $invoice instanceof Invoice || ! $invoice->credit_deal_id || ! $invoice->isService()) {
            return;
        }
        $deal = $invoice->creditDeal()->with('offer')->first();
        if ($deal) {
            $this->settle($deal, $e->by ?? Robot::user());
        }
    }

    /** Пересчитать сделку под засчитанные оплаты. */
    public function settle(Deal $deal, User $by): void
    {
        if (self::$running) {
            return;
        }
        self::$running = true;
        try {
            ($this->sync)($deal, $by);
        } finally {
            self::$running = false;
        }
    }
}
