<?php

namespace App\Http\Admin;

use App\Billing\PartyRules;
use App\Offers\Actions\SaveDealContract;
use App\Offers\Actions\SetContractPrice;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\DkpDocument;
use App\Support\Money;
use App\Support\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * ДКП сделки в CRM (05.10.2026): сотрудник по сканам страховой вносит продавца — собственника по СТС — и документы ТС;
 * договор — страницей на печать и PDF. Покупателя вносит менеджер (`Cabinet\DealContractController`) или админ за него.
 */
class DealContractController
{
    /** Плательщик сделки — один на счёт и ссылку (`SetDealPayer`, строка «Плательщик» в «Продаже», 16.11.2026). */
    public function payer(Request $request, Deal $deal, \App\Offers\Actions\SetDealPayer $set)
    {
        abort_unless($request->user()->canEditDeals() && $deal->buyer_id && $deal->isActive(), 404);
        $party = $set($deal, $request->user(), $request->validate(\App\Offers\Actions\SetDealPayer::rules(), \App\Offers\Actions\SetDealPayer::messages()));

        return back()->with('toast', 'Платит '.$party->name);
    }

    /** Кому платят услуги подбора — ПРАЙМ или Р.О.С. (`SetFeeSeller`, строка «Плательщик платит» в «Продаже», 16.11.2026). */
    public function feeSeller(Request $request, Deal $deal, \App\Offers\Actions\SetFeeSeller $set)
    {
        abort_unless($request->user()->canEditDeals() && $deal->isActive(), 404);
        $value = $request->validate(['fee_seller' => ['required', 'in:prime,ros']])['fee_seller'];
        $seller = \App\Billing\Seller::from($value);
        $set($deal, $request->user(), $seller);

        return back()->with('toast', 'Услуги подбора: '.($seller->config()['name'] ?? $value));
    }

    /** «Отправить менеджеру» у счёта сделки (16.11.2026): до того у менеджера «Счёт готовится». */
    public function showInvoice(Request $request, Deal $deal, \App\Billing\Invoice $invoice, \App\Offers\Actions\ShowToManager $show)
    {
        abort_unless($invoice->deal_id === $deal->id && $deal->buyer_id, 404);
        $show->invoice($deal, $invoice, $request->user());

        return back()->with('toast', \App\Billing\PayOffer::sent($invoice->fresh()));
    }

    /** «Отправить менеджеру» у договора (16.11.2026): до того у менеджера «Договор готовится». */
    public function showContract(Request $request, Deal $deal, \App\Offers\Actions\ShowToManager $show)
    {
        abort_unless($deal->hasContract() && $deal->buyer_id, 404);
        $show->contract($deal, $request->user());

        return back()->with('toast', 'Договор отправлен менеджеру');
    }

    /** Договор от страховой руками («страховой напрямую», 16.11.2026): файл к машине, открыт менеджеру (`SupplierContract`). */
    public function supplierContract(Request $request, Deal $deal)
    {
        abort_unless($request->user()->canEditDeals() && \App\Offers\SupplierContract::applies($deal), 404);
        $file = $request->validate(['file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,heic,doc,docx']], ['file.required' => 'Выберите файл'])['file'];
        \App\Offers\SupplierContract::attach($deal->offer, $file);
        $deal->offer->log(\App\Offers\OfferEventType::Note, $request->user(), ['text' => 'Договор от страховой приложен']);

        return back()->with('toast', 'Договор от страховой приложен');
    }

    public function update(Request $request, Deal $deal, SaveDealContract $save)
    {
        // Покупатель для счёта поставщика без договора (03.11.2026, `Deal::collectsBuyer`) — только его часть, без ДКП.
        abort_unless($deal->collectsBuyer() || $deal->contract?->buyer_user_id, 404);
        // Покупатель за менеджера (20.10.2026, владелец: «пока менеджер не укажет покупателя, мы не можем закрыть сделку,
        // должна быть возможность указывать за него»): тот же выбор, что у менеджера, — его покупатели, сам менеджер
        // (`me` — покупатель сделки), данные для договора и кто платит по счёту ПРАЙМ.
        if (($request->hasAny(['buyer_id', 'payer', 'buyer'])) && ! $request->has('vehicle')) {
            abort_unless($deal->isLive(), 404);
            $rules = ['buyer_id' => ['nullable', 'string'], 'payer' => ['nullable', 'in:buyer,manager']];
            if ($request->has('buyer')) {
                $rules += PartyRules::passport('buyer');
            }
            PartyRules::squeeze($request);
            $data = $request->validate($rules, [], ['buyer.name' => 'ФИО покупателя'] + PartyRules::buyerLabels());
            $contract = $save(DealContract::for($deal), $data, $request->user());
            // Выбрали покупателя без данных для договора — сразу их шторка со сканом паспорта, как у менеджера.
            $needs = ! empty($data['buyer_id']) && ($b = $contract->fresh()->buyer) && ! $b->party?->readyAsBuyer();

            return back()->with('open_sheet', $needs ? 'deal-buyer-data-'.$deal->id : null)
                ->with('toast', match (true) {
                    $deal->isPrime() && $deal->fresh()->hasManagerInvoice(sent: false) => 'Покупатель указан, счёт сформирован',
                    $request->has('buyer') => 'Данные покупателя сохранены',
                    default => 'Покупатель указан',
                });
        }
        abort_unless($deal->hasContract(), 404);
        // НДС договора — сегментом в карточке ДКП, сразу (14.11.2026, владелец: «бывает и без НДС, и 5 %, и 22 %»).
        if ($request->has('vat_rate') && ! $request->has('vehicle')) {
            // НДС — деньги ПРАЙМ: только админ (модератору — сумма договора и данные ДКП).
            abort_unless($request->user()->canManageCrm(), 403);
            $data = $request->validate(['vat_rate' => ['required', 'integer', Rule::in(DealContract::VAT_RATES)]], [], ['vat_rate' => 'НДС']);
            $contract = $save(DealContract::for($deal), $data, $request->user());

            return back()->with('toast', $contract->vat_rate ? 'ДКП: НДС '.$contract->vat_rate.' %' : 'ДКП без НДС');
        }
        // «Цена по ДКП» — только в форме ДКП собственника: у ПРАЙМ и Р.О.С. сумма — своей шторкой (`price`), без поля её не трогаем.
        $request->has('vehicle.price') && $request->merge(['vehicle' => array_merge((array) $request->input('vehicle'), ['price' => Money::rubles($request->input('vehicle.price')) ?: null])]);
        // Продавец вносится у ДКП (собственник по сканам) и собственник у ПРАЙМ (комитент по договору комиссии, 14.11.2026) —
        // если его вписали: пустые поля собственника (ещё не пришёл договор комиссии) цену и город сохранить не мешают;
        // собственник-организация (из договора комиссии) полями паспорта не правится.
        $prime = DealContract::for($deal)->form() === 'prime';
        $owner = $deal->isDkp() || ($prime && filled($request->input('seller.name')));
        $owner && PartyRules::squeeze($request, 'seller');
        $data = $request->validate(($owner ? PartyRules::passport('seller') : []) + ($prime ? ['commission_no' => ['nullable', 'string', 'max:40']] : []) + [
            'vehicle' => ['required', 'array'], 'vehicle.price' => ['nullable', 'integer', 'min:1'], 'vehicle.city' => ['nullable', 'string', 'max:80'],
        ] + collect(DealContract::VEHICLE)->keys()->mapWithKeys(fn ($k) => ["vehicle.{$k}" => ['nullable', 'string', 'max:40']])->all(),
            // Подписи полей ТС — те же, что в форме: «Кузов №», а не «vehicle.body_no» (07.10.2026).
            [], ['seller.name' => $prime ? 'ФИО собственника' : 'ФИО продавца', 'commission_no' => 'номер договора комиссии', 'vehicle.price' => 'цена по ДКП', 'vehicle.city' => 'город договора']
                + collect(DealContract::VEHICLE)->mapWithKeys(fn ($label, $k) => ["vehicle.{$k}" => $label])->all());
        $save(DealContract::for($deal), $data, $request->user());

        return back()->with('toast', 'ДКП сохранён');
    }

    /**
     * Сумма договора руками (14.11.2026, `SetContractPrice`): шторка «Сумма договора» в «Деньгах» и в окне документов
     * модератора. «Как в сделке» (`auto`) — снова по деньгам сделки.
     */
    public function price(Request $request, Deal $deal, SetContractPrice $set)
    {
        $auto = $request->boolean('auto');
        $request->merge(['price' => Money::rubles($request->input('price')) ?: null]);
        $data = $auto ? [] : $request->validate(['price' => ['required', 'integer', 'min:1']], [], ['price' => 'сумма договора']);
        $contract = $set($deal, $auto ? null : (int) $data['price'], $request->user());

        return back()->with('toast', 'Сумма договора '.Money::rub((int) $contract->price).($auto ? ', как в сделке' : ''));
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
        // ПРАЙМ — новый шаблон с договором комиссии (14.11.2026), Р.О.С. — прежний.
        $view = match ($contract->form()) {
            'owner' => 'deals.docs.dkp.owner', 'prime' => 'deals.docs.dkp.prime', default => 'deals.docs.dkp.sale'
        };
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
