<?php

namespace App\Billing\Actions;

use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Seller;
use App\Billing\WorkDays;
use App\Support\Money;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Другая сумма» у ручной ссылки, по которой ещё не платили: счёт перевыставляется на неё — тот же плательщик, услуга и
 * ТС. Ссылка на часть счёта оставила бы долг остатком, и после оплаты `EnsurePayLink` завёл бы на него вторую ссылку.
 */
final class ReissueServiceInvoice
{
    public function __construct(private IssueInvoice $issue, private VoidInvoice $void) {}

    public function __invoke(Invoice $old, User $by, float $amount): Invoice
    {
        if (! $old->isService() || $old->state !== InvoiceState::Issued || $old->paid > 0 || $old->claims()->exists()) {
            throw ValidationException::withMessages(['amount' => 'По счёту уже платили']);
        }
        $party = $old->party;
        $rate = Seller::Prime->vatRate();
        $total = $party->vat_on_top && $rate ? round($amount * (100 + $rate) / 100, 2) : round($amount, 2);
        $max = (float) config('xcar.yookassa.max_amount');
        if ($amount <= 0 || ($max > 0 && $total > $max + 0.005)) {
            throw ValidationException::withMessages(['amount' => 'От 1 ₽ до '.Money::exact($max).' за один платёж']);
        }
        $title = (string) $old->charges()->value('title');

        return DB::transaction(function () use ($old, $by, $amount, $party, $title) {
            ($this->void)($old, $by, 'Другая сумма');
            $new = ($this->issue)($party, $by, 'issued', ChargeKind::Service, WorkDays::add(now(), 3),
                lines: [['title' => $title, 'qty' => 1, 'unit' => 'svc', 'price' => round($amount, 2), 'kind' => ChargeKind::Service->value]], offerId: $old->offer_id);
            $new->update(['car_title' => $old->car_title, 'credit_deal_id' => $old->credit_deal_id]);

            return $new;
        });
    }
}
