<?php

namespace App\Http\Cabinet;

use App\Billing\PartyRules;
use App\Http\Admin\DealContractController as Document;
use App\Offers\Actions\SaveDealContract;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\DealState;
use Illuminate\Http\Request;

/**
 * ДКП у менеджера (05.10.2026): покупатель из его «Покупателей» или новый, паспорт — если его ещё нет; договор — тот же
 * документ, что в CRM (`Admin\DealContractController::document`).
 */
class DealContractController
{
    /** Плательщик сделки — один на счёт и ссылку (`SetDealPayer`, строка «Плательщик», 16.11.2026): менеджер выбирает сам. */
    public function payer(Request $request, Deal $deal, \App\Offers\Actions\SetDealPayer $set)
    {
        abort_unless($deal->buyer_id === $request->user()->id && $deal->isActive(), 404);
        $party = $set($deal, $request->user(), $request->validate(\App\Offers\Actions\SetDealPayer::rules(), \App\Offers\Actions\SetDealPayer::messages()));

        return back()->with('toast', $party->id === $request->user()->party_id ? 'Платите вы' : 'Платит '.$party->name);
    }

    /** «Запросить счёт…» у комиссии (18.11.2026): счёт подбора — документом с номером и PDF (`RequestFeeInvoice`). */
    public function paper(Request $request, Deal $deal, \App\Billing\Invoice $invoice, \App\Billing\Actions\RequestFeeInvoice $request_)
    {
        abort_unless($deal->buyer_id === $request->user()->id && $invoice->deal_id === $deal->id && $invoice->isVisibleToManager($request->user()), 404);
        $request_($invoice, $request->user());

        return back()->with('toast', 'Счёт запрошен');
    }

    public function update(Request $request, Deal $deal, SaveDealContract $save)
    {
        // Покупатель для счёта поставщика без договора — тоже здесь (03.11.2026, `Deal::collectsBuyer`).
        abort_unless(($deal->collectsBuyer() || $deal->contract?->buyer_user_id) && $deal->isLive(), 404);
        $new = $request->input('buyer_id') === 'new';
        $rules = ['buyer_id' => ['nullable', 'string'], 'payer' => ['nullable', 'in:buyer,manager']];
        if ($new) {
            $rules += ['new_buyer.phone' => ['nullable', 'string', 'max:20']];
        }
        if ($request->has('buyer') || $new) {
            $rules += PartyRules::passport('buyer');
        }
        PartyRules::squeeze($request);
        $data = $request->validate($rules, [], ['buyer.name' => 'ФИО покупателя'] + PartyRules::buyerLabels());
        if ($new) {
            $data['new_buyer'] = ['phone' => $request->input('new_buyer.phone')];
            unset($data['buyer_id']);
        }
        $contract = $save(DealContract::for($deal), $data, $request->user());
        // Выбрали покупателя, а данных для договора у него нет — сразу их шторка со сканом паспорта (18.10.2026).
        $needs = ! empty($data['buyer_id']) && ($b = $contract->fresh()->buyer) && ! $b->party?->readyAsBuyer();

        return back()->with('open_sheet', $needs ? 'deal-buyer-data' : null)->with('toast', match (true) {
            $new => 'Покупатель добавлен',
            $deal->isPrime() && $deal->fresh()->hasManagerInvoice() => 'Сохранено, счёт выставлен',
            default => 'Сохранено',
        });
    }

    public function show(Request $request, Deal $deal)
    {
        abort_unless($deal->hasContract(), 404);
        abort_if($deal->state === DealState::Cancelled, 404);
        // Менеджеру — только готовый договор: с прочерками вместо покупателя его не печатают (06.10.2026).
        // Исправленный (`ContractEdit`) — его, даже если в данных сделки чего-то теперь не хватает.
        $edited = \App\Offers\ContractEdit::has($deal);
        abort_unless($edited || DealContract::for($deal)->isReady(), 404);
        // И только отправленный кнопкой в CRM (16.11.2026): до того у менеджера «Договор готовится». Сотрудник видит всегда.
        abort_unless($request->user()?->isAdmin() || ($edited ? DealContract::for($deal)->shown_at !== null : DealContract::for($deal)->shownToManager()), 404);

        // Word и PDF (19.11.2026, владелец: «спрашивалось, в docx скачать или в pdf») — одна дверь с CRM.
        return Document::file($deal, match (true) {
            str_ends_with($request->path(), '.pdf') => 'pdf',
            str_ends_with($request->path(), '.docx') => 'docx',
            default => 'html',
        });
    }
}
