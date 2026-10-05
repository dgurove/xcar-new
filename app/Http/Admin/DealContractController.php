<?php

namespace App\Http\Admin;

use App\Billing\PartyRules;
use App\Offers\Actions\SaveDealContract;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Support\Pdf;
use Illuminate\Http\Request;

/**
 * ДКП сделки в CRM (05.10.2026): сотрудник по сканам страховой вносит продавца — собственника по СТС — и документы ТС;
 * договор — страницей на печать и PDF. Покупателя вносит менеджер (`Cabinet\DealContractController`).
 */
class DealContractController
{
    public function update(Request $request, Deal $deal, SaveDealContract $save)
    {
        abort_unless($deal->isDkp(), 404);
        $request->merge(['vehicle' => array_merge((array) $request->input('vehicle'), ['price' => preg_replace('/\D+/', '', (string) $request->input('vehicle.price')) ?: null])]);
        $data = $request->validate(PartyRules::passport('seller') + [
            'vehicle' => ['required', 'array'], 'vehicle.price' => ['nullable', 'integer', 'min:1'], 'vehicle.city' => ['nullable', 'string', 'max:80'],
        ] + collect(DealContract::VEHICLE)->keys()->mapWithKeys(fn ($k) => ["vehicle.{$k}" => ['nullable', 'string', 'max:'.($k === 'pts_issued' ? 255 : 40)]])->all(),
            [], ['seller.name' => 'ФИО продавца']);
        $save(DealContract::for($deal), $data, $request->user());

        return back()->with('toast', 'ДКП сохранён');
    }

    public function show(Request $request, Deal $deal)
    {
        abort_unless($deal->isDkp(), 404);

        return self::document($deal, $request->routeIs('*.pdf') || str_ends_with($request->path(), '.pdf'));
    }

    /** Договор страницей или PDF — одна дверь для CRM и кабинета менеджера. */
    public static function document(Deal $deal, bool $pdf)
    {
        $contract = DealContract::for($deal)->load(['seller', 'buyer.party', 'deal.offer.brand', 'deal.offer.model']);
        if (! $pdf) {
            return view('deals.docs.dkp', ['contract' => $contract]);
        }
        $name = 'dkp-'.$deal->offer->number.'.pdf';

        return response(Pdf::render('deals.docs.dkp', ['contract' => $contract]), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$name.'"',
        ]);
    }
}
