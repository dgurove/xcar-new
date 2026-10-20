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
 * договор — страницей на печать и PDF. Покупателя вносит менеджер (`Cabinet\DealContractController`) или админ за него.
 */
class DealContractController
{
    public function update(Request $request, Deal $deal, SaveDealContract $save)
    {
        abort_unless($deal->hasContract(), 404);
        // Покупатель за менеджера (20.10.2026, владелец: «пока менеджер не укажет покупателя, мы не можем закрыть сделку,
        // должна быть возможность указывать за него»): тот же выбор, что у менеджера, — его покупатели, сам менеджер
        // (`me` — покупатель сделки), данные для договора и кто платит по счёту ПРАЙМ.
        if (($request->hasAny(['buyer_id', 'payer', 'buyer'])) && ! $request->has('vehicle')) {
            abort_unless($deal->isActive(), 404);
            $rules = ['buyer_id' => ['nullable', 'string'], 'payer' => ['nullable', 'in:buyer,manager']];
            if ($request->has('buyer')) {
                $rules += PartyRules::passport('buyer');
            }
            $data = $request->validate($rules, [], ['buyer.name' => 'ФИО покупателя']);
            $contract = $save(DealContract::for($deal), $data, $request->user());
            // Выбрали покупателя без данных для договора — сразу их шторка со сканом паспорта, как у менеджера.
            $needs = ! empty($data['buyer_id']) && ($b = $contract->fresh()->buyer) && ! $b->party?->readyAsBuyer();

            return back()->with('open_sheet', $needs ? 'deal-buyer-data-'.$deal->id : null)
                ->with('toast', $deal->isPrime() && $deal->fresh()->hasManagerInvoice() ? 'Покупатель указан, счёт выставлен' : 'Покупатель указан');
        }
        // Дата договора — своей формой из строки-календаря карточки (19.10.2026).
        if ($request->has('signed_at') && ! $request->has('vehicle')) {
            $contract = $save(DealContract::for($deal), $request->validate(['signed_at' => ['required', 'date']], [], ['signed_at' => 'дата договора']), $request->user());

            return back()->with('toast', 'Дата договора '.$contract->signed_at->translatedFormat('j F'));
        }
        $request->merge(['vehicle' => array_merge((array) $request->input('vehicle'), ['price' => Money::rubles($request->input('vehicle.price')) ?: null])]);
        // Продавец вносится только у ДКП (собственник по сканам); у ПРАЙМ продавец — ПРАЙМ.
        $data = $request->validate(($deal->isDkp() ? PartyRules::passport('seller') : []) + [
            'vehicle' => ['required', 'array'], 'vehicle.price' => ['nullable', 'integer', 'min:1'], 'vehicle.city' => ['nullable', 'string', 'max:80'],
        ] + collect(DealContract::VEHICLE)->keys()->mapWithKeys(fn ($k) => ["vehicle.{$k}" => ['nullable', 'string', 'max:40']])->all(),
            // Подписи полей ТС — те же, что в форме: «Кузов №», а не «vehicle.body_no» (07.10.2026).
            [], ['seller.name' => 'ФИО продавца', 'vehicle.price' => 'цена по ДКП', 'vehicle.city' => 'город договора']
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
