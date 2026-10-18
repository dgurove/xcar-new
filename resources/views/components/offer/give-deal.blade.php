{{-- «Отдать» в сделку (`Give`, 18.10.2026): кому и за сколько (без подтверждения — вписывают тут, по умолчанию цена
     продажи), деньги сделки формой `x-offer.money-form` (схема, сумма собственнику, вознаграждение), кто забирает ТС.
     С подтверждением — его принятие: кому и цена — из него; не выше закупочной — переспрашивает. Отдать другому —
     прежняя сделка отменится. --}}
@props(['offer', 'bid' => null, 'prefix' => 'give'])
@php
    use App\Offers\DealScheme;
    use App\Support\Money;
    $handOver = $offer->deal;
    $floor = $bid?->frozenCost() ?? $offer->bidFloor();
    $under = $bid && $floor && $bid->amount !== null ? $floor - $bid->amount : null;
    $loss = $under !== null && $under >= 0;
    $amount = $bid?->amount ?? (Money::rubles(old('amount')) ?: $offer->asking_price);
@endphp
<x-offer.money-form :action="$bid ? '/confirmations/'.$bid->id.'/accept' : '/offers/'.$offer->number.'/give'" :amount="$amount" :priced="! $bid" :cost="$floor" :supplier-cost="$offer->floor_price"
    :scheme="DealScheme::choiceFor($offer->vendor?->deal_format, DealScheme::forOffer($offer))" :schemes="DealScheme::casesFor($offer->vendor)" :owner-price="$offer->owner_price"
    :fee="! $bid?->user->isSuperBuyer()" :submit="$handOver ? 'Отдать' : ($bid ? 'Принять' : 'Отдать')" :id="$prefix.'-commission'"
    :note="$handOver ? 'Сделка с '.$handOver->buyer?->shortName().' отменится' : null"
    :confirm="$loss ? 'Принять за '.Money::rub($bid->amount).' при закупочной '.Money::rub($floor).'? '.($under > 0 ? 'Сделка в минус '.Money::rub($under) : 'Наценки нет') : null">
    <input type="hidden" name="to" value="deal">
    @if ($loss)<input type="hidden" name="loss" value="1">@endif
    @error('give')<x-ui.flash tone="danger">{{ $message }}</x-ui.flash>@enderror
    @unless ($bid)
        <div class="fields">
            <x-ui.combobox name="manager_id" label="Кому" url="/reference/managers" placeholder="Имя или телефон" :id="$prefix.'-deal-manager'" required/>
            <x-ui.field name="amount" label="Цена менеджеру, ₽" :value="$amount ? Money::nums($amount) : null" :id="$prefix.'-amount'" required
                data-commission-target="price" data-action="input->commission#input"/>
        </div>
    @endunless
    <x-offer.pickup-choice :offer="$offer" :manager="$bid?->user"/>
</x-offer.money-form>
