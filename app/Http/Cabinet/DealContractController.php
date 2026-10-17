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
    public function update(Request $request, Deal $deal, SaveDealContract $save)
    {
        abort_unless($deal->buyer_id === $request->user()->id && $deal->hasContract() && $deal->isActive(), 404);
        $new = $request->input('buyer_id') === 'new';
        $rules = ['buyer_id' => ['nullable', 'string'], 'payer' => ['nullable', 'in:buyer,manager']];
        if ($new) {
            $rules += ['new_buyer.phone' => ['nullable', 'string', 'max:20']];
        }
        if ($request->has('buyer') || $new) {
            $rules += PartyRules::passport('buyer');
        }
        $data = $request->validate($rules, [], ['buyer.name' => 'ФИО покупателя']);
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
        abort_unless($deal->buyer_id === $request->user()->id && $deal->hasContract(), 404);
        abort_if($deal->state === DealState::Cancelled, 404);
        // Менеджеру — только готовый договор: с прочерками вместо покупателя его не печатают (06.10.2026).
        abort_unless(DealContract::for($deal)->isReady(), 404);

        return Document::document($deal, str_ends_with($request->path(), '.pdf'));
    }
}
