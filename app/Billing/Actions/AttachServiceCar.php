<?php

namespace App\Billing\Actions;

use App\Billing\Acquiring\ExistingLink;
use App\Billing\Acquiring\LinkExists;
use App\Billing\Acquiring\PayLink;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Billing\Listeners\CreditDealOnManual;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Offers\Offer;
use App\Offers\OfferEventType;
use App\Support\Money;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ТС у ручной ссылки потом, из её карточки (09.10.2026): старые 19 ручных ссылок без машины, оплаченные тоже — так деньги,
 * взятые до сделки (Тужиков, 53 000 7 октября), засчитываются в её сделку. Предложение системы — `offer_id` и сделка
 * (`credit_deal_id`): живая, иначе последняя не отменённая; не из системы — словами. Неоплаченную к машине, у сделки
 * которой уже своя открытая ссылка, не привязываем — ссылка на сделку одна (`LinkExists`). Обе сделки — прежняя и
 * новая — пересчитываются (`CreditDealOnManual::settle`).
 */
final class AttachServiceCar
{
    public function __construct(private CreditDealOnManual $credit) {}

    public function __invoke(Invoice $invoice, ?Offer $offer, ?string $car, User $by): Invoice
    {
        if (! $invoice->isService() || $invoice->state === InvoiceState::Void) {
            throw ValidationException::withMessages(['offer_id' => 'ТС меняют только у разовой оплаты']);
        }
        $car = $offer ? null : (PayLink::squash((string) $car) ?: null);
        $unpaid = $invoice->state === InvoiceState::Issued && $invoice->paid == 0;
        if ($unpaid && $offer && ($found = ExistingLink::forOffer($offer)) && $found->invoice_id !== $invoice->id) {
            throw new LinkExists($found);
        }
        $before = $invoice->creditDeal;
        $deal = $offer ? (Deal::where('offer_id', $offer->id)->where('state', DealState::Active)->first()
            ?? Deal::where('offer_id', $offer->id)->where('state', '!=', DealState::Cancelled)->latest('id')->first()) : null;
        DB::transaction(function () use ($invoice, $offer, $car, $deal, $by) {
            $invoice->update(['offer_id' => $offer?->id, 'car_title' => $car, 'credit_deal_id' => $deal?->id]);
            $offer?->log(OfferEventType::Note, $by, ['text' => 'Ссылка на оплату '.Money::rub($invoice->total).' ('.$invoice->party->name.')'.($deal ? ' засчитана в сделку' : ' по этой ТС')]);
        });
        foreach (collect([$before, $deal])->filter()->unique('id') as $d) {
            $this->credit->settle($d->fresh(), $by, advance: $invoice->paid > 0);
        }

        return $invoice->fresh();
    }
}
