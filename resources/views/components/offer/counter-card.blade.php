{{-- Контрпредложение менеджеру (26.10.2026): цена админа крупно, своя — строкой ниже, комментарий цитатой. «Согласен» —
     сразу сделка (переспрашивает: машина закрепится по этой цене), «Отказаться» — своя цена остаётся. Стоит над формой
     подтверждения и над «Ваше подтверждение», пока контр живой (`Bid::hasPendingCounter`). --}}
@props(['bid'])
@php use App\Support\Money; @endphp
@if ($bid?->hasPendingCounter())
    <x-offer.answer-card {{ $attributes }} caption="Контрпредложение" :sum="$bid->counter_amount" :quote="$bid->counter_comment"
        :sub="'ваша цена '.Money::rub($bid->amount).', на '.Money::rub($bid->counter_amount - $bid->amount).' выше'"
        :action="'/confirmations/'.$bid->id.'/counter/answer'" :agree="'Согласен за '.Money::rub($bid->counter_amount)"
        :agree-ask="'Согласиться на '.Money::rub($bid->counter_amount).'?'" agree-text="ТС закрепится за вами по этой цене"
        decline-ask="Отказаться от контрпредложения?" :decline-text="'Ваша цена '.Money::rub($bid->amount).' останется в силе'" error="counter"/>
@endif
