<?php

namespace App\Billing\Listeners;

use App\Billing\ChargeKind;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Workflow\Events\StageEntered;
use App\Workflow\Track;
use Illuminate\Support\Carbon;

/**
 * Срок подбора идёт с шага оплаты (06.10.2026, владелец: «почему он уже должник, если ещё не забрал и не продал»). Счёт
 * подбора выставлен при принятии без срока (`SyncDealInvoices::dueFor`), ссылка у менеджера есть сразу; вошли на шаг
 * оплаты — срок счёта = часы шага. Оплачено раньше — шаг проходит сам (`AdvanceOnPayment::entered`), счёт не ждёт.
 */
final class StartSelectionDue
{
    public function handle(StageEntered $e): void
    {
        if ($e->track !== Track::Sale || ! $e->to->isPayStep()) {
            return;
        }
        $deal = $e->deal ?? $e->offer->deal()->first();
        if (! $deal?->isActive()) {
            return;
        }
        $e->offer->unsetRelation('positions');
        $due = $e->offer->position(Track::Sale)?->deadline_at ?? Carbon::now()->addDays(3);
        Invoice::where('deal_id', $deal->id)->where('direction', 'issued')->where('kind', ChargeKind::Selection)
            ->where('state', InvoiceState::Issued)->whereNull('due_at')->update(['due_at' => $due->toDateString()]);
    }
}
