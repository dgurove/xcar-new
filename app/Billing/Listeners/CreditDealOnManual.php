<?php

namespace App\Billing\Listeners;

use App\Billing\Actions\IssueAgentFee;
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
 * пересчитывает свои счета (`SyncDealInvoices`: подбор меньше, покрыт — не нужен). Сошлось целиком — шаг оплаты и
 * выплата менеджеру, как от оплаты её собственного счёта: у ручного счёта `deal_id` нет, и `AdvanceOnPayment` его не видит.
 */
final class CreditDealOnManual
{
    /** Пересчёт сам гасит ручные счета (`retireCredits`) — их события сюда не возвращаются. */
    private static bool $running = false;

    public function __construct(private SyncDealInvoices $sync, private AdvanceOnPayment $advance, private IssueAgentFee $fee) {}

    public function handle(PaymentRecorded|PaymentVoided|InvoiceVoided $e): void
    {
        $invoice = $e instanceof PaymentVoided ? $e->payment->invoice : $e->invoice;
        if (self::$running || ! $invoice instanceof Invoice || ! $invoice->credit_deal_id || ! $invoice->isService()) {
            return;
        }
        $deal = $invoice->creditDeal()->with('offer')->first();
        if (! $deal) {
            return;
        }
        $this->settle($deal, $e->by ?? Robot::user(), advance: $e instanceof PaymentRecorded);
    }

    /** Пересчитать сделку под засчитанные оплаты; `advance` — деньги пришли: сошлось целиком — шаг оплаты и выплата. */
    public function settle(Deal $deal, User $by, bool $advance = true): void
    {
        if (self::$running || ! $deal->isActive()) {
            return;
        }
        self::$running = true;
        try {
            ($this->sync)($deal, $by);
            $deal->refresh()->load('offer');
            if ($advance && $deal->agreed() && $deal->fullyPaid()) {
                $this->advance->dealPaid($deal, $by);
                ($this->fee)($deal, $by);
            }
        } finally {
            self::$running = false;
        }
    }
}
