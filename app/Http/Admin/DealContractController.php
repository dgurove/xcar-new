<?php

namespace App\Http\Admin;

use App\Billing\PartyRules;
use App\Offers\Actions\SaveDealContract;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\DkpDocument;
use App\Support\Money;
use App\Support\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * ДКП сделки в CRM (05.10.2026): сотрудник по сканам страховой вносит продавца — собственника по СТС — и документы ТС;
 * договор — страницей на печать и PDF. Покупателя вносит менеджер (`Cabinet\DealContractController`).
 */
class DealContractController
{
    public function update(Request $request, Deal $deal, SaveDealContract $save)
    {
        abort_unless($deal->hasContract(), 404);
        $request->merge(['vehicle' => array_merge((array) $request->input('vehicle'), ['price' => Money::rubles($request->input('vehicle.price')) ?: null])]);
        // Продавец вносится только у ДКП (собственник по сканам); у ПРАЙМ продавец — ПРАЙМ.
        $data = $request->validate(($deal->isDkp() ? PartyRules::passport('seller') : []) + [
            'vehicle' => ['required', 'array'], 'vehicle.price' => ['nullable', 'integer', 'min:1'], 'vehicle.city' => ['nullable', 'string', 'max:80'],
            'vehicle.signed_at' => ['nullable', 'date'],
        ] + collect(DealContract::VEHICLE)->keys()->mapWithKeys(fn ($k) => ["vehicle.{$k}" => ['nullable', 'string', 'max:40']])->all(),
            // Подписи полей ТС — те же, что в форме: «Кузов №», а не «vehicle.body_no» (07.10.2026).
            [], ['seller.name' => 'ФИО продавца', 'vehicle.price' => 'цена по ДКП', 'vehicle.city' => 'город договора', 'vehicle.signed_at' => 'дата договора']
                + collect(DealContract::VEHICLE)->mapWithKeys(fn ($label, $k) => ["vehicle.{$k}" => $label])->all());
        $save(DealContract::for($deal), $data, $request->user());

        return back()->with('toast', 'ДКП сохранён');
    }

    public function show(Request $request, Deal $deal)
    {
        abort_unless($deal->hasContract(), 404);

        return self::document($deal, $request->routeIs('*.pdf') || str_ends_with($request->path(), '.pdf'));
    }

    /**
     * Договор страницей или PDF — одна дверь для CRM и кабинета менеджера. Вид по шаблону владельца (19.10.2026):
     * физлицо физлицу или ДКП с актом от ПРАЙМ / Р.О.С. (`DealContract::form`), данные собирает `DkpDocument`.
     */
    public static function document(Deal $deal, bool $pdf)
    {
        $contract = DealContract::for($deal)->load(['seller', 'buyer.party', 'deal.offer.car', 'deal.buyer']);
        $doc = new DkpDocument($contract);
        $view = $contract->form() === 'owner' ? 'deals.docs.dkp.owner' : 'deals.docs.dkp.sale';
        if (! $pdf) {
            return view($view, ['doc' => $doc]);
        }
        $name = $doc->fileName();

        return response(Pdf::render($view, ['doc' => $doc]), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.Str::ascii($name).'"; filename*=UTF-8\'\''.rawurlencode($name),
        ]);
    }
}
