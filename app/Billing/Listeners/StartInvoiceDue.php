<?php

namespace App\Billing\Listeners;

use App\Billing\ChargeKind;
use App\Billing\Documents\InvoicePdf;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Notifications\MoneyNotice;
use App\Workflow\Events\StageEntered;
use App\Workflow\Track;
use Illuminate\Support\Carbon;

/**
 * Срок счетов сделки идёт с шага оплаты (подбор — с 06.10.2026, владелец: «почему он уже должник, если ещё не забрал и не
 * продал»; счёт ПРАЙМ за ТС — с 16.11.2026: «счёт формируется сразу, сроками мозг не выносить, потом наступает этап оплаты —
 * счёт переформировывается, и в нём уже строка про срок»). До шага счёт без срока (`SyncDealInvoices::dueFor`); вошли на шаг
 * оплаты — неоплаченным счетам срок = часы шага, PDF перерисовывается с «Оплатить до» (номер тот же). Счёт уже у менеджера
 * (`shown_at`) — ему «оплатить до …» сразу; не отправлен — увидит со сроком, когда отправят. Откат с шага — срок снимается.
 */
final class StartInvoiceDue
{
    private const KINDS = [ChargeKind::Sale, ChargeKind::Selection];

    public function __construct(private InvoicePdf $pdf) {}

    public function handle(StageEntered $e): void
    {
        if ($e->track !== Track::Sale) {
            return;
        }
        $deal = $e->deal ?? $e->offer->deal()->first();
        if (! $deal) {
            return;
        }
        $open = fn () => Invoice::where('deal_id', $deal->id)->where('direction', 'issued')->whereIn('kind', self::KINDS)
            ->where('state', InvoiceState::Issued)->with(['charges', 'party', 'payments']);
        // Шаг оплаты отменили назад («Отменить», «Вернуть на шаг» — без исхода): срок снимается у неоплаченного счёта — иначе
        // он висел «Просрочен», пока менеджер ещё связывается с владельцем (11.10.2026). Вернутся на оплату — встанет заново.
        if ($e->exit === null && $e->from?->isPayStep() && ! $e->to->isPayStep()) {
            $open()->where('paid', 0)->whereDoesntHave('payments')->whereNotNull('due_at')->get()
                ->each(fn (Invoice $i) => $this->retime($i, null));

            return;
        }
        if (! $e->to->isPayStep() || ! $deal->isActive()) {
            return;
        }
        $e->offer->unsetRelation('positions');
        $due = $deal->payDue() ?? $e->offer->position(Track::Sale)?->deadline_at ?? Carbon::now()->addDays(14);
        foreach ($open()->whereNull('due_at')->get() as $invoice) {
            $this->retime($invoice, $due);
            if ($invoice->shown_at && $deal->credited() <= 0) {
                $deal->buyer?->notify(MoneyNotice::invoiceIssued($invoice->fresh(['party', 'deal.offer', 'deal.buyer'])));
            }
        }
    }

    private function retime(Invoice $invoice, ?Carbon $due): void
    {
        $invoice->update(['due_at' => $due?->toDateString()]);
        $this->pdf->attach($invoice->fresh(['charges', 'party', 'payments']));
    }
}
