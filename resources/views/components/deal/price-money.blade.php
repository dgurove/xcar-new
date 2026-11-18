{{-- Цена продажи и вознаграждение сделки одной формой (18.11.2026, владелец: «пусть цену продажи и сумму вознаграждения тоже могут
     менять», и модератор): `x-offer.money-form` с полем цены (`priced`) → `PUT /work/deals/{id}/money` (`DealController::money`).
     Шторка «Цена и вознаграждение» в «⋯» «Продажи» и окно «Счёт невозможно сформировать» (`x-deal.invoice-gaps`) — одна форма.
     Гаражная Совкомбанка без цены — как «Продаю» (машина «Продана», вознаграждение по доле, если не вписано). --}}
@props(['deal', 'submit' => 'Сохранить', 'hint' => null])
@php use App\Support\Money; @endphp
<x-offer.money-form :action="'/work/deals/'.$deal->id.'/money'" method="put" :amount="$deal->amount" priced :cost="$deal->cost" :commission="$deal->commission" :fee="! $deal->isDirect()"
    :scheme="\App\Offers\DealScheme::choiceFor($deal->offer->vendor?->deal_format, $deal->schemeOf())" scheme-locked :owner-price="$deal->owner_price" :owner-locked="true"
    :supplier-cost="$deal->supplier_cost" :share="$deal->isGarage() ? null : $deal->buyer?->deal_share" :prefill="$deal->commission === null" :submit="$submit" :id="'deal-price-'.$deal->id">
    <div class="flex flex-col gap-3">
        <x-ui.field name="amount" label="Цена продажи, ₽" :value="$deal->amount ? Money::nums($deal->amount) : null" :id="'deal-price-'.$deal->id.'-amount'" required
            data-commission-target="price" data-action="input->commission#input"/>
        @if ($hint)<p class="text-sm text-ink-muted">{{ $hint }}</p>@endif
    </div>
</x-offer.money-form>
