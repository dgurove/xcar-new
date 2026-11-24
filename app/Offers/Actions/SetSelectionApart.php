<?php

namespace App\Offers\Actions;

use App\Billing\Actions\VoidInvoice;
use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Offers\Deal;
use App\Offers\OfferEventType;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Подбор отдельной ссылкой или в счёте за ТС (24.11.2026, владелец: «по умолчанию счёт единый, можно функцию, чтобы отдельно
 * ссылкой»; `Deal::splitsSelection`). Пока по счетам не платили (`Deal::moneyEditable`): включили — счёт за ТС встаёт на
 * закупочную, подбор — своей ссылкой; выключили — неоплаченная ссылка подбора гаснет, счёт за ТС встаёт одной суммой.
 */
final class SetSelectionApart
{
    public function __construct(private SyncDealInvoices $invoices, private VoidInvoice $void) {}

    public function __invoke(Deal $deal, bool $apart, User $by): void
    {
        if (! $deal->canSplitSelection()) {
            throw ValidationException::withMessages(['selection_apart' => 'Подбор отдельно — только у счёта ПРАЙМ за ТС']);
        }
        if (! $deal->moneyEditable()) {
            throw ValidationException::withMessages(['selection_apart' => 'По счёту сделки уже платили, сначала отмените оплату']);
        }
        if ($deal->selection_apart === $apart) {
            return;
        }
        DB::transaction(function () use ($deal, $apart, $by) {
            $deal->update(['selection_apart' => $apart]);
            $deal->offer->log(OfferEventType::Note, $by, ['text' => $apart ? 'Подбор отдельной ссылкой' : 'Подбор в счёте за ТС']);
            if (! $apart) {
                Invoice::where('deal_id', $deal->id)->where('direction', 'issued')->where('kind', ChargeKind::Selection)->where('state', InvoiceState::Issued)
                    ->with(['payments', 'claims'])->get()
                    ->filter(fn (Invoice $i) => $i->claims->isEmpty() && $i->payments->whereNull('voided_at')->isEmpty())
                    ->each(fn (Invoice $i) => ($this->void)($i, $by, 'Подбор в счёте за ТС'));
            }
            ($this->invoices)($deal->fresh(), $by);
        });
    }
}
