{{-- Цена продажи сделки одной шторкой (18.11.2026, владелец выбрал «Г + А»: «они путают, что такое сумма договора и что такое цена
     продажи»): цена продажи, вознаграждение — `x-offer.money-form` с полем цены (`priced`) → `PUT /work/deals/{id}/money`
     (`DealController::money`); ниже — НДС договора сегментом (сохраняется сразу, `PUT /work/deals/{id}/contract`). Открывают: строки
     «Продажи», пункт «⋯» и окно «Счёт невозможно сформировать» (`x-deal.invoice-gaps`). Гаражная Совкомбанка без цены — как «Продаю»
     (машина «Продана», вознаграждение по доле, если не вписано).
     25.11.2026 (владелец выбрал из макетов «Б»: «нужны шкалы, что куда от цены продажи»): у ПРАЙМ / Р.О.С. две полосы — вознаграждение
     «менеджеру | остаётся» и под ним остаток «в счёт | ссылкой» (`x-deal.invoice-split`, сколько на счёт за ТС — `car_price`); «Наличными
     мимо счёта» (`deal_contracts.cash_part`) — строкой «+», раскрыта, когда сумма есть. Переключателя «подбор в счёте | ссылкой» больше нет. --}}
@props(['deal', 'submit' => 'Сохранить'])
@php
    use App\Offers\DealContract;
    use App\Support\Money;
    $contract = $deal->hasContract() && $deal->schemeOf()->paysCar() ? DealContract::for($deal) : null;
    $total = $contract ? DealContract::priceFor($deal) : null;
    $fid = 'deal-price-'.$deal->id;
    // Делить счёт и ссылку — у ПРАЙМ / Р.О.С. с известными ценой и закупочной (шторку и так открывают, только пока по счетам не платили);
    // вознаграждение полоса берёт из той же формы.
    $split = $contract && $deal->canSplitSelection();
    $cash = old('cash_part', $contract?->cash_part);
    // Закупочная ПРАЙМ / Р.О.С. — первым полем (25.11.2026, владелец: «закупочную в этом окне», «первым закупочная, вторым продажная»).
    $costEdit = $deal->isPrime() && ! $deal->isGarage() && ! $deal->isAuction() && $deal->cost !== null;
@endphp
<x-offer.money-form :action="'/work/deals/'.$deal->id.'/money'" method="put" :amount="$deal->amount" priced :cost="$deal->cost" :commission="$deal->commission ?? ($deal->namesBuyer() ? $deal->suggestedCommission() : null)" :stored="$deal->commission" :fee="! $deal->isDirect()"
    :scheme="\App\Offers\DealScheme::choiceFor($deal->offer->vendor?->deal_format, $deal->schemeOf())" scheme-locked :owner-price="$deal->owner_price" :owner-locked="true"
    :supplier-cost="$deal->supplier_cost" :share="$deal->isGarage() ? null : $deal->buyer?->deal_share" :prefill="$deal->commission === null" :submit="$submit" :id="$fid"
    :rest="$split ? 'остаётся' : 'Подбор'" tone="mgr" :cost-editable="$costEdit" cost-first cost-label="Закупочная, ₽" rub-label="Вознаграждение, ₽">
    <div class="flex flex-col gap-3">
        <x-ui.field name="amount" label="Цена продажи, ₽" :value="$deal->amount ? Money::nums($deal->amount) : null" :id="$fid.'-amount'" required
            data-commission-target="price" data-action="input->commission#input"/>
    </div>
    @if ($contract)
        <x-slot:after>
            @if ($split)
                <x-deal.invoice-split :deal="$deal" :id="$fid"/>
            @elseif ($total !== null)
                <p class="text-sm text-ink-muted">Счёт и договор: <span class="nums">{{ Money::rub($total) }}</span></p>
            @endif
            {{-- Редкое (владелец: «сумма договора меняется в очень редких случаях, когда часть наличными отдали») — строкой «+». --}}
            <details class="cash-part" @if ($cash || $errors->has('cash_part')) open @endif>
                <summary class="text-accent-text">+ Наличными мимо счёта</summary>
                <x-ui.field name="cash_part" label="Наличными мимо счёта, ₽" :value="$cash ? Money::nums((int) Money::rubles((string) $cash)) : null" :id="$fid.'-cash'" placeholder="0"/>
            </details>
        </x-slot:after>
    @endif
</x-offer.money-form>
@if ($contract && auth()->user()?->canEditDeals())
    <form method="post" action="/work/deals/{{ $deal->id }}/contract" data-controller="autosubmit" data-sheet-clean class="mt-4 flex flex-col gap-1.5">
        @csrf @method('put')
        {{-- Ставок больше трёх (20.11.2026, бухгалтер: в счёте за ТС — НДС того, у кого забираем машину, 0 / 5 / 7 / 10 / 20 / 22) —
             выбором; откуда ставка, если не выбрана руками, — строкой ниже (`DealContract::vatSource`). --}}
        <label class="text-sm text-ink-muted" for="deal-vat-{{ $deal->id }}">НДС в договоре и счёте за ТС</label>
        <select id="deal-vat-{{ $deal->id }}" name="vat_rate" class="field-input" data-action="change->autosubmit#submit">
            @foreach (\App\Billing\Vat::RATES as $r)
                <option value="{{ $r }}" @selected($contract->vatRate() === $r)>{{ \App\Billing\Vat::label($r) }}</option>
            @endforeach
        </select>
        @if ($why = $contract->vatSource())<span class="text-sm text-ink-muted">{{ $why }}</span>@endif
    </form>
@endif
