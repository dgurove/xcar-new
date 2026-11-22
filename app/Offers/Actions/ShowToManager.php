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

    /** Отозвать можно отправленный и ещё не оплаченный: ни денег, ни заявленной менеджером оплаты. */
    public static function canRecallInvoice(Invoice $invoice): bool
    {
        return $invoice->deal_id && $invoice->direction === 'issued' && $invoice->shown_at !== null && $invoice->state === InvoiceState::Issued
            && $invoice->paidMoney() <= 0 && ! $invoice->claims()->exists();
    }

    /**
     * «Отозвать» отправленный счёт (19.11.2026, владелец: «ОЧЕНЬ не хватает возможности отозвать счёт и договор, чтобы поправить и
     * заново отправить»): менеджер его больше не видит («Счёт готовится»), открытая ссылка на оплату гаснет, на шаге оплаты ход
     * снова наш (`InvoiceRecalled` → `PassTurnOnInvoice`). Номер и дата остаются: отправят снова — тот же счёт.
     */
    public function recallInvoice(Deal $deal, Invoice $invoice, User $by): Invoice
    {
        $done = DB::transaction(function () use ($deal, $invoice, $by) {
            $invoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if ($invoice->deal_id !== $deal->id || ! $invoice->shown_at) {
                return null;
            }
            if (! self::canRecallInvoice($invoice)) {
                throw ValidationException::withMessages(['show' => 'По счёту уже платили: отозвать нельзя']);
            }
            $invoice->update(['shown_at' => null, 'shown_by' => null]);
            foreach ($invoice->payLinks()->get() as $link) {
                app(\App\Billing\Acquiring\Actions\CancelPayLink::class)($link, $by);
            }
            $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Счёт '.$invoice->label().' отозван у менеджера']);

            return $invoice;
        });
        if ($done) {
            \App\Billing\Events\InvoiceRecalled::dispatch($done->fresh(), $by);
            $what = 'Счёт'.($done->number ? ' № '.$done->docNo() : '');
            $deal->buyer?->notify(new \App\Notifications\DocRecalledNotice($deal, $what));
            \App\Notifications\Notify::toStaff(\App\Notifications\DealEventNotice::recalled($deal->loadMissing('offer'), $what, $by), $by);
        }

        return $done ?? $invoice->fresh();
    }

    /** Отозвать можно отправленный и не подписанный: подписанный приложен — договор уже на бумаге. */
    public static function canRecallContract(Deal $deal): bool
    {
        return DealContract::for($deal)->shown_at !== null && ! $deal->contract_signed_at && $deal->getMedia('signed_contract')->isEmpty();
    }

    /** «Отозвать» отправленный договор: менеджер видит «Договор готовится»; номер остаётся. */
    public function recallContract(Deal $deal, User $by): DealContract
    {
        $done = DB::transaction(function () use ($deal, $by) {
            $c = DealContract::whereKey(DealContract::for($deal)->id)->lockForUpdate()->firstOrFail();
            if (! $c->shown_at) {
                return null;
            }
            if (! self::canRecallContract($deal)) {
                throw ValidationException::withMessages(['show' => 'Подписанный договор уже приложен: отозвать нельзя']);
            }
            $c->update(['shown_at' => null, 'shown_by' => null]);
            $deal->offer->log(OfferEventType::Note, $by, ['text' => 'Договор'.($c->code ? ' № '.$c->code : '').' отозван у менеджера']);

            return $c;
        });
        if ($done) {
            $what = 'Договор'.($done->code ? ' № '.$done->code : '');
            $deal->buyer?->notify(new \App\Notifications\DocRecalledNotice($deal, $what));
            \App\Notifications\Notify::toStaff(\App\Notifications\DealEventNotice::recalled($deal->loadMissing('offer'), $what, $by), $by);
            \App\Live\RefreshDeal::of($deal);
        }

        return $done ?? DealContract::for($deal)->fresh();
    }

    public function contract(Deal $deal, User $by): DealContract
    {
        $contract = DealContract::for($deal);
        if (! $contract->sendable()) {
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
