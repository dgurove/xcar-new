<?php

namespace App\Offers\Actions;

use App\Billing\Events\InvoiceShown;
use App\Billing\Invoice;
use App\Billing\InvoiceState;
use App\Notifications\ContractReadyNotice;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\OfferEventType;
use App\Users\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * «Отправить менеджеру» у счёта и договора сделки в CRM (16.11.2026, владелец: «чтобы сотрудники могли проверить всё ли верно,
 * перед тем как отправляется»; «даже если срок оплаты наступил, договор и счёт ему не отправляются, пока сотрудники не нажмут»).
 * До того менеджер видит «Счёт готовится» / «Договор готовится» (`Invoice::shownToManager`, `DealContract::shownToManager`).
 * Счёт: уведомление, ссылка подбору, на шаге оплаты — ход менеджера (слушатели `InvoiceShown`). Договор — `ContractReadyNotice`.
 */
final class ShowToManager
{
    public function invoice(Deal $deal, Invoice $invoice, User $by): Invoice
    {
        $shown = DB::transaction(function () use ($deal, $invoice, $by) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->deal_id !== $deal->id || $invoice->direction !== 'issued' || $invoice->state === InvoiceState::Void) {
                throw ValidationException::withMessages(['show' => 'Этот счёт уже не действует']);
            }
            if ($invoice->shown_at) {
                return null;
            }
            // Номер и дата счёта — днём отправки (`Numbering::assign`, 16.11.2026): до неё счёт без номера.
            \App\Billing\Numbering::assign($invoice);
            $invoice->update(['shown_at' => now(), 'shown_by' => $by->id]);
            $deal->offer->log(OfferEventType::Note, $by, ['text' => \App\Billing\PayOffer::sent($invoice)]);

            return $invoice;
        });
        if ($shown) {
            InvoiceShown::dispatch($shown->fresh(), $by);
            Disclose::publish($deal);
            $this->toChat($deal, $shown->fresh());
        }

        return $shown ?? $invoice->fresh();
    }

    /**
     * Комиссия выставлена — ссылкой в чат с менеджером (18.11.2026, владелец: «„Выставить“ — тогда она отправляется в чат
     * менеджеру, отображается в сделке и копируется в буфер обмена»). Уведомление «Ссылка на оплату» тогда не шлём — его
     * заменяет сообщение чата (`Notify::invoiceIssued`).
     */
    private function toChat(Deal $deal, Invoice $invoice): void
    {
        if ($invoice->isDocument() || ! $deal->buyer) {
            return;
        }
        $link = $invoice->openLink();
        $text = \App\Billing\PayOffer::line($invoice).($link ? "\nОплата картой, СБП или SberPay по ссылке:\n".$link->url() : '');
        rescue(fn () => app(\App\Chats\Actions\PlatformMessage::class)(app(\App\Chats\Actions\OpenChat::class)($deal->offer, $deal->buyer, greet: false), $text), report: true);
    }

    public function contract(Deal $deal, User $by): DealContract
    {
        $contract = DealContract::for($deal);
        if (! $contract->isReady()) {
            throw ValidationException::withMessages(['show' => 'Договор ещё не готов: '.implode(', ', $contract->missing())]);
        }
        $fresh = DB::transaction(function () use ($contract, $deal, $by) {
            $c = DealContract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            if ($c->shown_at) {
                return null;
            }
            // Номер — днём отправки (`ContractNumber`): в той же транзакции, сорвалась отправка — номер не выдан.
            $code = \App\Offers\ContractNumber::assign($c);
            $c->update(['shown_at' => now(), 'shown_by' => $by->id]);
            $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Договор'.($code ? ' № '.$code : '').' отправлен менеджеру']);

            return $c;
        });
        if ($fresh) {
            $deal->buyer?->notify(new ContractReadyNotice($deal));
            Disclose::publish($deal);
        }

        return $fresh ?? $contract->fresh();
    }
}
