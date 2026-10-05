<?php

namespace App\Billing\Listeners;

use App\Billing\ChargeKind;
use App\Billing\Events\InvoiceIssued;
use App\Billing\Events\InvoiceVoided;
use App\Offers\Deal;
use App\Workflow\Position;
use App\Workflow\Requirement;
use App\Workflow\Track;
use App\Workflow\WaitsFor;
use Illuminate\Support\Carbon;

/**
 * Ход на этапе оплаты идёт за счётом (05.10.2026, владелец: без счёта обе стороны ждали друг друга). Выставили счёт
 * сделке, что ждёт нас на этапе оплаты, — ход менеджера: позиция без своего хода, часы этапа от сейчас, просьба
 * «Оплатите счёт» со сроком. Сам менеджер узнаёт о счёте уведомлением `invoiceIssued`. Аннулировали последний —
 * ход снова наш: просьба снята, часов нет.
 */
final class PassTurnOnInvoice
{
    public function handle(InvoiceIssued|InvoiceVoided $e): void
    {
        $invoice = $e->invoice;
        if ($invoice->isOwed() || ! $invoice->deal_id || $invoice->kind === ChargeKind::Reward) {
            return;
        }
        $deal = Deal::with('buyer')->find($invoice->deal_id);
        $position = Position::where('offer_id', $deal?->offer_id)->where('track', Track::Sale)->with('stage.exits')->first();
        if (! $deal?->isActive() || ! $position?->stage->isPayStep()) {
            return;
        }

        // Ход наш (выставить) или менеджера «Укажите покупателя» (ПРАЙМ) — счёт встал: «Оплатите счёт» с часами.
        if ($e instanceof InvoiceIssued && in_array($position->waits_for, [WaitsFor::Us, WaitsFor::Manager], true)) {
            $position->update([
                'waits_for' => null, 'deadline_at' => $position->stage->deadlineFor($deal->offer, Carbon::now()),
                'reminded_at' => null, 'overdue_at' => null,
            ]);
            $open = Requirement::where('deal_id', $deal->id)->where('stage_id', $position->stage_id)->whereNull('done_at')->exists();
            if (! $open && $deal->buyer_id && $position->stage->awaitsManager($deal)) {
                Requirement::askFor($deal, $position->stage, $position);
            }
        } elseif ($e instanceof InvoiceVoided && $position->waits_for === null && ! $deal->hasManagerInvoice()) {
            $position->update(['waits_for' => $deal->invoiceGap() === 'buyer' ? WaitsFor::Manager : WaitsFor::Us, 'deadline_at' => null, 'reminded_at' => null, 'overdue_at' => null]);
            Requirement::where('deal_id', $deal->id)->where('stage_id', $position->stage_id)->whereNull('done_at')->delete();
        }
    }
}
