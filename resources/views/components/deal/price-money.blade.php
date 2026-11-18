{{-- Цена продажи сделки одной шторкой (18.11.2026, владелец выбрал «Г + А»: «они путают, что такое сумма договора и что такое цена
     продажи»): цена продажи, вознаграждение и «Часть наличными мимо счёта» (`deal_contracts.cash_part`) — `x-offer.money-form` с полем
     цены (`priced`) → `PUT /work/deals/{id}/money` (`DealController::money`); ниже — во что это выходит («Счёт и договор: N»,
     `DealContract::priceFor`) и НДС договора сегментом (сохраняется сразу, `PUT /work/deals/{id}/contract`). Суммы договора руками
     нет: договор и счёт считаются сами. Открывают: цена в шапке «Продажи», пункт «⋯» и окно «Счёт невозможно сформировать»
     (`x-deal.invoice-gaps`). Гаражная Совкомбанка без цены — как «Продаю» (машина «Продана», вознаграждение по доле, если не вписано). --}}
@props(['deal', 'submit' => 'Сохранить'])
@php
    use App\Offers\DealContract;
    use App\Support\Money;
    $contract = $deal->hasContract() && $deal->schemeOf()->paysCar() ? DealContract::for($deal) : null;
    $total = $contract ? DealContract::priceFor($deal) : null;
    $nb = fn (string $s) => str_replace(' ', "\u{00A0}", $s);
    $fid = 'deal-price-'.$deal->id;
@endphp
<x-offer.money-form :action="'/work/deals/'.$deal->id.'/money'" method="put" :amount="$deal->amount" priced :cost="$deal->cost" :commission="$deal->commission" :fee="! $deal->isDirect()"
    :scheme="\App\Offers\DealScheme::choiceFor($deal->offer->vendor?->deal_format, $deal->schemeOf())" scheme-locked :owner-price="$deal->owner_price" :owner-locked="true"
    :supplier-cost="$deal->supplier_cost" :share="$deal->isGarage() ? null : $deal->buyer?->deal_share" :prefill="$deal->commission === null" :submit="$submit" :id="$fid">
    <div class="flex flex-col gap-3">
        <x-ui.field name="amount" label="Цена продажи, ₽" :value="$deal->amount ? Money::nums($deal->amount) : null" :id="$fid.'-amount'" required
            data-commission-target="price" data-action="input->commission#input"/>
        @if ($contract)
            {{-- Редкий случай (владелец: «сумма договора меняется в очень редких случаях, когда часть наличными отдали»). --}}
            <x-ui.field name="cash_part" label="Часть наличными мимо счёта, ₽" :value="$contract->cash_part ? Money::nums($contract->cash_part) : null" :id="$fid.'-cash'" placeholder="0"/>
            @if ($total !== null)<p class="text-sm text-ink-muted">Счёт и договор: <span class="nums">{{ Money::rub($total) }}</span></p>@endif
        @endif
    </div>
</x-offer.money-form>
@if ($contract && auth()->user()?->canEditDeals())
    <form method="post" action="/work/deals/{{ $deal->id }}/contract" data-controller="autosubmit" data-sheet-clean class="mt-4 flex flex-col gap-1.5">
        @csrf @method('put')
        <span class="text-sm text-ink-muted">НДС в договоре и счёте</span>
        <x-ui.segment name="vat_rate" :options="collect(DealContract::VAT_RATES)->mapWithKeys(fn ($r) => [$r => $r ? $nb($r.' %') : $nb('Без НДС')])->all()" :value="$contract->vatRate()" data-action="change->autosubmit#submit"/>
    </form>
@endif
