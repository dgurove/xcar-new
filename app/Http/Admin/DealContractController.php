<?php

namespace App\Http\Admin;

use App\Billing\PartyRules;
use App\Offers\Actions\EditContract;
use App\Offers\Actions\SaveDealContract;
use App\Offers\ContractEdit;
use App\Offers\Deal;
use App\Offers\DealContract;
use App\Offers\DkpDocument;
use App\Offers\DkpWord;
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
        abort_unless($request->user()->canEditDeals() && $deal->buyer_id && $deal->moneyOpen(), 404);
        $party = $set($deal, $request->user(), $request->validate(\App\Offers\Actions\SetDealPayer::rules(), \App\Offers\Actions\SetDealPayer::messages()));

        return back()->with('toast', 'Платит '.$party->name);
    }

    /**
     * Собственник по договору комиссии — шторка «Собственник» (`x-deal.owner`, 25.11.2026): сам поставщик, распознанный из
     * договора комиссии, поправить нынешнего или другой — физлицо, ИП или организация (`SetDealOwner`).
     */
    public function owner(Request $request, Deal $deal, \App\Offers\Actions\SetDealOwner $set)
    {
        abort_unless($request->user()->canEditDeals() && \App\Offers\Actions\SetDealOwner::allows($deal), 404);
        $mode = $request->validate(['mode' => ['required', Rule::in(\App\Offers\Actions\SetDealOwner::MODES)]])['mode'];
        $rules = ['commission_no' => ['nullable', 'string', 'max:40']];
        if (in_array($mode, ['edit', 'new'], true)) {
            PartyRules::squeeze($request, 'owner');
            $rules += PartyRules::passport('owner', owner: true);
        }
        $data = $request->validate($rules, [], ['owner.name' => 'ФИО'] + PartyRules::buyerLabels('owner'));
        $party = $set($deal, $request->user(), $mode, $data);

        return back()->with('toast', 'Собственник: '.\App\Offers\Actions\SetDealOwner::label($party));
    }

    /** Кому платят услуги подбора — ПРАЙМ или Р.О.С. (`SetFeeSeller`, строка «Плательщик платит» в «Продаже», 16.11.2026). */
    public function feeSeller(Request $request, Deal $deal, \App\Offers\Actions\SetFeeSeller $set)
    {
        abort_unless($request->user()->canEditDeals() && $deal->moneyOpen(), 404);
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

    /**
     * «Отправить менеджеру» одной кнопкой счёт за ТС и ссылку на подбор (25.11.2026, владелец: «блоки со ссылкой на подбор и счётом
     * объединить и у них обоих сделать кнопку «Отправить», которая и то и то отправляет»): каждый, что менеджер ещё не видит, — тем же
     * `ShowToManager::invoice`, что «Отправить менеджеру» у счёта и «Выставить» у ссылки (ссылка — в чат).
     */
    public function sendBills(Request $request, Deal $deal, \App\Offers\Actions\ShowToManager $show)
    {
        abort_unless($deal->buyer_id && $request->user()->canEditDeals(), 404);
        $bills = $deal->issuedInvoices()->whereIn('kind', [\App\Billing\ChargeKind::Sale, \App\Billing\ChargeKind::Selection])
            ->where('state', \App\Billing\InvoiceState::Issued)->whereNull('shown_at')->get();
        abort_if($bills->isEmpty(), 404);
        \Illuminate\Support\Facades\DB::transaction(fn () => $bills->each(fn ($i) => $show->invoice($deal, $i, $request->user())));

        return back()->with('toast', $bills->count() > 1 ? 'Счёт и ссылка на подбор у менеджера' : \App\Billing\PayOffer::sent($bills->first()->fresh()));
    }

    /** Счёт за ТС и ссылка на подбор сделки (не аннулированные), ключами `sale` и `selection`. @return array<string, \App\Billing\Invoice> */
    private function pairBills(Deal $deal): array
    {
        $bills = $deal->issuedInvoices()->whereIn('kind', [\App\Billing\ChargeKind::Sale, \App\Billing\ChargeKind::Selection])
            ->where('state', '!=', \App\Billing\InvoiceState::Void)->latest('id')->get();

        return array_filter(['sale' => $bills->firstWhere('kind', \App\Billing\ChargeKind::Sale), 'selection' => $bills->firstWhere('kind', \App\Billing\ChargeKind::Selection)]);
    }

    /** Поля шторки оплаты (`x-deal.payment-sheet`): за что, как, сколько, когда. @return array{0: \App\Billing\Invoice, 1: float, 2: \App\Billing\PaymentSource, 3: \Illuminate\Support\Carbon} */
    private function paymentFields(Request $request, Deal $deal): array
    {
        $request->merge(['amount' => blank($request->input('amount')) ? null : \App\Support\Money::rubles($request->input('amount'))]);
        $data = $request->validate(['for' => ['required', 'in:sale,selection'], 'source' => ['required', 'in:bank,cash'], 'amount' => ['required', 'integer', 'min:1'],
            'paid_at' => ['nullable', 'date']], ['amount.required' => 'Сколько получили', 'for.required' => 'За что оплата']);
        $invoice = $this->pairBills($deal)[$data['for']] ?? throw \Illuminate\Validation\ValidationException::withMessages(['for' => 'Такого счёта у сделки нет']);

        return [$invoice, (float) $data['amount'], \App\Billing\PaymentSource::from($data['source']),
            isset($data['paid_at']) ? \Illuminate\Support\Carbon::parse($data['paid_at'], 'Europe/Moscow') : now()];
    }

    /** Оплату вписали руками в этой сделке (наличные или банк без операции выписки и ЮKassa) — её можно поправить и удалить. */
    public static function manualPayment(\App\Billing\Payment $p): bool
    {
        return ! $p->voided_at && in_array($p->source, [\App\Billing\PaymentSource::Cash, \App\Billing\PaymentSource::Bank], true)
            && ! \App\Billing\Bank\Transaction::where('payment_id', $p->id)->exists()
            && ! \App\Billing\Acquiring\AcquiringPayment::where('payment_id', $p->id)->exists();
    }

    /**
     * «Оплата получена…» группы «Счёт и подбор» (25.11.2026, владелец: «выбор, за что оплата получена, в какой форме и сколько — счёт
     * оплатили частью наличными или подбор частью или полностью», «и чтобы редактировать можно было»): одна оплата за раз.
     */
    public function billsPaid(Request $request, Deal $deal, \App\Billing\Actions\RecordPayment $record)
    {
        abort_unless($request->user()->canEditDeals(), 404);
        [$invoice, $amount, $source, $at] = $this->paymentFields($request, $deal);
        $record($invoice, $request->user(), $amount, $at, $source);

        return back()->with('toast', 'Записано: '.\App\Support\Money::rub($amount).' '.($invoice->isDocument() ? 'за счёт за ТС' : 'за подбор'));
    }

    /** Правка вписанной руками оплаты: прежняя гаснет, новая встаёт с новыми полями (одной транзакцией). */
    public function updatePayment(Request $request, Deal $deal, \App\Billing\Payment $payment, \App\Billing\Actions\RecordPayment $record, \App\Billing\Actions\VoidPayment $void)
    {
        abort_unless($request->user()->canEditDeals() && $payment->invoice?->deal_id === $deal->id && self::manualPayment($payment), 404);
        \Illuminate\Support\Facades\DB::transaction(function () use ($request, $deal, $payment, $record, $void) {
            $void($payment, $request->user(), 'Исправлено');
            [$invoice, $amount, $source, $at] = $this->paymentFields($request, $deal->fresh());
            $record($invoice, $request->user(), $amount, $at, $source);
            $deal->offer->log(\App\Offers\OfferEventType::Note, $request->user(), ['text' => 'Оплата исправлена: '.\App\Support\Money::rub((float) $payment->amount).' → '
                .\App\Support\Money::rub($amount).($source === \App\Billing\PaymentSource::Cash ? ' наличными' : ' безналом').' '.($invoice->isDocument() ? 'за счёт за ТС' : 'за подбор')]);
        });

        return back()->with('toast', 'Оплата исправлена');
    }

    public function deletePayment(Request $request, Deal $deal, \App\Billing\Payment $payment, \App\Billing\Actions\VoidPayment $void)
    {
        abort_unless($request->user()->canEditDeals() && $payment->invoice?->deal_id === $deal->id && self::manualPayment($payment), 404);
        $void($payment, $request->user(), 'Удалена в сделке');
        $deal->offer->log(\App\Offers\OfferEventType::Note, $request->user(), ['text' => 'Оплата удалена: '.\App\Support\Money::rub((float) $payment->amount)]);

        return back()->with('toast', 'Оплата удалена');
    }

    /** «Напомнить» группы «Счёт и подбор»: про счёт и ссылку — одним сообщением (`NudgeInvoice::nowMany`), позже — обоим по плану. */
    public function nudgeBills(Request $request, Deal $deal, \App\Billing\Actions\NudgeInvoice $nudge)
    {
        abort_unless($request->user()->canEditDeals() && $deal->buyer_id, 404);
        $data = $request->validate(['when' => ['required', 'in:now,days,cancel'], 'days' => ['nullable', 'integer', 'min:1', 'max:30']]);
        $bills = collect($this->pairBills($deal))->values();
        if ($data['when'] === 'cancel') {
            $bills->each(fn ($i) => $nudge->cancel($i, $request->user()));

            return back()->with('toast', 'Не напоминаем');
        }
        $due = $bills->filter(fn ($i) => \App\Billing\Actions\NudgeInvoice::allows($i));
        if ($due->isEmpty()) {
            return back()->with('toast', 'Напоминать не о чем: оплачено или менеджер уже приложил документ об оплате');
        }
        if ($data['when'] === 'days') {
            $due->each(fn ($i) => $nudge->later($i, (int) ($data['days'] ?? 1), $request->user()));

            return back()->with('toast', 'Напомним '.$due->first()->fresh()->nudge_at->timezone('Europe/Moscow')->format('d.m').' в 10:00');
        }
        $nudge->nowMany($due, $request->user());

        return back()->with('toast', 'Напомнили менеджеру');
    }

    /** «Отозвать» счёт за ТС и ссылку на подбор вместе (25.11.2026, пункт «⋯» группы «Счёт и подбор»): что ещё можно отозвать. */
    public function recallBills(Request $request, Deal $deal, \App\Offers\Actions\ShowToManager $show)
    {
        abort_unless($request->user()->canEditDeals(), 404);
        $bills = $deal->issuedInvoices()->whereIn('kind', [\App\Billing\ChargeKind::Sale, \App\Billing\ChargeKind::Selection])->get()
            ->filter(fn ($i) => \App\Offers\Actions\ShowToManager::canRecallInvoice($i));
        abort_if($bills->isEmpty(), 404);
        \Illuminate\Support\Facades\DB::transaction(fn () => $bills->each(fn ($i) => $show->recallInvoice($deal, $i, $request->user())));

        return back()->with('toast', 'Счёт и ссылка на подбор отозваны у менеджера');
    }

    /** «Отозвать» отправленный счёт (19.11.2026, `ShowToManager::recallInvoice`). */
    public function recallInvoice(Request $request, Deal $deal, \App\Billing\Invoice $invoice, \App\Offers\Actions\ShowToManager $show)
    {
        abort_unless($invoice->deal_id === $deal->id, 404);
        $show->recallInvoice($deal, $invoice, $request->user());

        return back()->with('toast', 'Счёт отозван у менеджера');
    }

    /**
     * «Напомнить менеджеру» про отправленный счёт (19.11.2026, `NudgeInvoice`): `when=now` — сейчас, `days` — через столько дней в 10:00,
     * `cancel` — «Не напоминать» (снять план).
     */
    public function nudgeInvoice(Request $request, Deal $deal, \App\Billing\Invoice $invoice, \App\Billing\Actions\NudgeInvoice $nudge)
    {
        abort_unless($invoice->deal_id === $deal->id && $deal->buyer_id, 404);
        $data = $request->validate(['when' => ['required', 'in:now,days,cancel'], 'days' => ['nullable', 'integer', 'min:1', 'max:30']]);
        if ($data['when'] === 'cancel') {
            $nudge->cancel($invoice, $request->user());

            return back()->with('toast', 'Не напоминаем');
        }
        if (! \App\Billing\Actions\NudgeInvoice::allows($invoice)) {
            return back()->with('toast', 'Напоминать не о чем: счёт оплачен или менеджер уже приложил документ об оплате');
        }
        if ($data['when'] === 'days') {
            $nudge->later($invoice, (int) ($data['days'] ?? 1), $request->user());

            return back()->with('toast', 'Напомним '.$invoice->fresh()->nudge_at->timezone('Europe/Moscow')->format('d.m').' в 10:00');
        }
        $nudge->now($invoice, $request->user());

        return back()->with('toast', 'Напомнили менеджеру');
    }

    /** «Отозвать» отправленный договор (19.11.2026, `ShowToManager::recallContract`). */
    public function recallContract(Request $request, Deal $deal, \App\Offers\Actions\ShowToManager $show)
    {
        abort_unless($deal->hasContract(), 404);
        $show->recallContract($deal, $request->user());

        return back()->with('toast', 'Договор отозван у менеджера');
    }

    /**
     * «Выставить» комиссию (18.11.2026): то же «Отправить менеджеру» (`ShowToManager` — в чат ссылкой), ответ — JSON с текстом для
     * буфера обмена: `issue_controller` кладёт его туда на том же нажатии (ClipboardItem с обещанием).
     */
    public function issueInvoice(Request $request, Deal $deal, \App\Billing\Invoice $invoice, \App\Offers\Actions\ShowToManager $show)
    {
        abort_unless($invoice->deal_id === $deal->id && $deal->buyer_id, 404);
        $invoice = $show->invoice($deal, $invoice, $request->user());
        $link = $invoice->openLink();

        return response()->json([
            'copy' => $link ? $link->message()."\n".$link->url() : \App\Billing\PayOffer::line($invoice),
            'toast' => $link ? 'Выставлено, ссылка в чате и в буфере' : 'Выставлено, менеджер видит в сделке',
        ]);
    }

    /** «Запросили счёт…» у комиссии (18.11.2026): счёт подбора — документом с номером и PDF (`RequestFeeInvoice`). */
    public function paperInvoice(Request $request, Deal $deal, \App\Billing\Invoice $invoice, \App\Billing\Actions\RequestFeeInvoice $paper)
    {
        abort_unless($invoice->deal_id === $deal->id, 404);
        $paper($invoice, $request->user());

        return back()->with('toast', 'Счёт за подбор выставлен');
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
            // НДС договора — и модератору (18.11.2026, владелец: «полный доступ ко всему, что касается договоров и счетов»).
            abort_unless($request->user()->canEditDeals(), 403);
            $data = $request->validate(['vat_rate' => ['required', 'integer', Rule::in(\App\Billing\Vat::RATES)]], [], ['vat_rate' => 'НДС']);
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
     * Шторка «Счёт невозможно сформировать» (18.11.2026, `x-deal.invoice-gaps`): вид покупателя сегментом и поля счёта этого вида —
     * обязательными; «Сформировать без прописки» (`without_reg`) — отметка у договора, если физлицу не хватает одной прописки.
     * Дальше — та же дверь, что у данных покупателя (`SaveDealContract`).
     */
    public function invoiceData(Request $request, Deal $deal, SaveDealContract $save)
    {
        abort_unless($deal->isLive() && ($buyer = $deal->invoiceBuyer()) && $deal->contract, 404);
        $party = \App\Billing\Party::forUser($buyer);
        // Вид — из сегмента окна (18.11.2026, владелец: «указание типа покупателя, если его нет»), по умолчанию прежний.
        $was = match ($party->kind) { \App\Billing\PartyKind::Company => 'company', \App\Billing\PartyKind::Entrepreneur => 'ip', default => 'person' };
        $kind = in_array($request->input('buyer.kind'), ['person', 'ip', 'company'], true) ? $request->input('buyer.kind') : $was;
        $without = $kind === 'person' && $request->boolean('without_reg') && blank($request->input('buyer.reg_address'));
        // Обязательны поля счёта этого вида (`Party::invoiceGaps`), остальное — правилами общих полей покупателя.
        $need = match ($kind) {
            'company' => ['org' => 'название', 'inn' => 'ИНН', 'kpp' => 'КПП', 'legal_address' => 'юридический адрес'],
            'ip' => ['name' => 'ФИО', 'inn' => 'ИНН', 'ogrn' => 'ОГРНИП', 'legal_address' => 'адрес'],
            default => ['name' => 'ФИО'] + ($without ? [] : ['reg_address' => 'адрес прописки']),
        };
        $all = PartyRules::passport('buyer');
        $rules = [];
        foreach ($need as $k => $label) {
            $base = array_values(array_filter((array) ($all['buyer.'.$k] ?? ['string', 'max:255']), fn ($r) => ! is_string($r) || (! str_starts_with($r, 'required') && $r !== 'nullable')));
            $rules['buyer.'.$k] = ['required', ...$base];
        }
        PartyRules::squeeze($request);
        $data = $request->validate($rules, [], collect($need)->mapWithKeys(fn ($l, $k) => ['buyer.'.$k => $l])->all());
        $save($deal->contract, ['buyer' => ['kind' => $kind] + ($data['buyer'] ?? [])] + ($without ? ['invoice_without_reg' => true] : []), $request->user());

        return back()->with('toast', $deal->fresh()->hasSaleInvoice() ? 'Счёт сформирован' : 'Данные покупателя сохранены');
    }

    public function show(Request $request, Deal $deal, EditContract $edit)
    {
        abort_unless($deal->hasContract(), 404);
        // «Исправить договор» → «Скачать Word» (`?edit=1`, 19.11.2026): номер договора выдаётся сейчас (`EditContract::word`), чтобы в
        // исправленном файле он уже стоял. Предзагрузку (наведение мыши, `Sec-Purpose: prefetch`) номер не трогает.
        if ($request->boolean('edit') && str_ends_with($request->path(), '.docx') && ! self::prefetch($request) && $request->user()->canEditDeals()
            && EditContract::allows($deal)) {
            $edit->word($deal, $request->user());
            $deal = $deal->fresh();
        }

        return self::file($deal, match (true) {
            str_ends_with($request->path(), '.pdf') => 'pdf',
            str_ends_with($request->path(), '.docx') => 'docx',
            default => 'html',
        });
    }

    /** Приложить исправленный договор (Word или PDF). */
    public function edited(Request $request, Deal $deal, EditContract $edit, \App\Offers\Actions\ShowToManager $show)
    {
        abort_unless($request->user()->canEditDeals(), 404);
        $file = $request->validate(['file' => ['required', 'file', 'max:20480']],
            ['file.required' => 'Выберите заполненный договор', 'file.max' => 'Файл больше 20 МБ'])['file'];
        $edit->attach($deal, $file, $request->user());
        // «Сохранить и отправить» (23.11.2026, владелец): приложенный сразу менеджеру — той же дверью, что «Отправить менеджеру».
        if ($request->boolean('send') && $deal->buyer_id) {
            $show->contract($deal->fresh(), $request->user());

            return back()->with('toast', 'Договор заполнен и отправлен менеджеру');
        }

        return back()->with('toast', 'Заполненный договор приложен');
    }

    /** «Вернуть сформированный»: исправленный убран, договор снова из данных сделки. */
    public function unedit(Request $request, Deal $deal, EditContract $edit)
    {
        abort_unless($request->user()->canEditDeals(), 404);
        $edit->revert($deal, $request->user());

        return back()->with('toast', 'Договор снова сформирован из данных сделки');
    }

    /**
     * Договор файлом — одна дверь CRM и кабинета менеджера (19.11.2026): `pdf`, `docx` (Word, `DkpWord`) или `html` — страница
     * печати. Есть исправленный (`ContractEdit`) — отдаётся он: PDF — его PDF, Word — сам файл (приложили PDF — Word нет),
     * страница — его PDF. Иначе — сформированный из данных сделки сейчас.
     */
    public static function file(Deal $deal, string $format, bool $attachment = false)
    {
        $name = preg_replace('/\.pdf$/', '', (new DkpDocument(DealContract::for($deal)))->fileName());
        if (ContractEdit::has($deal)) {
            $media = $format === 'docx' ? (ContractEdit::hasWord($deal) ? ContractEdit::original($deal) : null) : ContractEdit::pdf($deal);
            abort_if($media === null, 404);
            $ext = strtolower(pathinfo($media->file_name, PATHINFO_EXTENSION));

            return self::download((string) stream_get_contents($media->stream()), $name.'.'.$ext, $media->mime_type ?: 'application/octet-stream', $attachment || $format === 'docx');
        }

        if ($format === 'docx') {
            // LibreOffice не отработал (нет в окружении, завис) — словами, а не 500: PDF при этом скачивается как обычно.
            try {
                $bytes = DkpWord::docx($deal);
            } catch (\RuntimeException $e) {
                report($e);
                abort(503, 'Word сейчас не собрать, скачайте PDF');
            }

            return self::download($bytes, $name.'.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', true);
        }

        return match ($format) {
            'pdf' => self::document($deal, true),
            default => self::document($deal, false),
        };
    }

    private static function prefetch(Request $request): bool
    {
        return str_contains(strtolower((string) ($request->header('Sec-Purpose') ?? $request->header('X-Sec-Purpose') ?? $request->header('Purpose'))), 'prefetch');
    }

    private static function download(string $bytes, string $name, string $mime, bool $attachment)
    {
        return response($bytes, 200, ['Content-Type' => $mime, 'Cache-Control' => 'private, no-store',
            'Content-Disposition' => ($attachment ? 'attachment' : 'inline').'; filename="'.Str::ascii($name).'"; filename*=UTF-8\'\''.rawurlencode($name)]);
    }

    /**
     * Договор страницей или PDF — одна дверь для CRM и кабинета менеджера. Вид по шаблону владельца (19.10.2026):
     * физлицо физлицу или ДКП с актом от ПРАЙМ / Р.О.С. (`DealContract::form`), данные собирает `DkpDocument`.
     */
    public static function document(Deal $deal, bool $pdf, bool $word = false)
    {
        $contract = DealContract::for($deal)->load(['seller', 'buyer.party', 'deal.offer.car', 'deal.buyer']);
        $doc = new DkpDocument($contract);
        // ПРАЙМ — новый шаблон с договором комиссии (14.11.2026), Р.О.С. — прежний.
        $view = match ($contract->form()) {
            'owner' => 'deals.docs.dkp.owner', 'prime' => 'deals.docs.dkp.prime', default => 'deals.docs.dkp.sale'
        };
        if (! $pdf) {
            // Word (`Offers\DkpWord`) — та же разметка без печати, номеров страниц и знака «ОБРАЗЕЦ».
            return view($view, ['doc' => $doc] + ($word ? ['word' => true] : []));
        }
        $name = $doc->fileName();

        return response(Pdf::render($view, ['doc' => $doc]), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.Str::ascii($name).'"; filename*=UTF-8\'\''.rawurlencode($name),
        ]);
    }
}
