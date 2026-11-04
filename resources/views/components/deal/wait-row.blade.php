{{-- Строка «Ждём менеджера» / «Ждём поставщика» в «Сделках» CRM (04.11.2026, владелец: «непонятно, КОГО ждём и ЧЕГО ждём»):
     кого — заголовок группы (`DealController::waiting`), здесь — машина, вендор с убытком, **что ждём** (этап: «Нет реквизитов»,
     «Оплата счёта ПРАЙМ», «Письмо о покупке») и срок: «до 05.11 17:00», «просрочено 2 д», «ждём ответа 3 д», без срока — «ждём
     3 д». На шаге оплаты со счётом срок — счёта (`DealDebt`). На телефоне что и срок — второй строкой под названием.
     Столбцы — `DealController::table(waiting: true)`. --}}
@props(['deal', 'table'])
@php
    $offer = $deal->offer;
    $position = $offer->position();
    $debt = \App\Billing\DealDebt::of($deal);
    $payDue = $debt && ! $debt->paid() && $position?->stage->isPayStep();
    $what = $position ? $position->stage->name : $deal->state->label();
@endphp
<tr data-detail-key="{{ $deal->id }}" data-search-row id="deal-{{ $deal->id }}">
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('title')
    <td class="{{ $col->classes() }}">
        <x-cell.title :key="$deal->id" :offer="$offer"/>
        <span class="cell-sub sm:hidden"><span class="text-ink">{{ $what }}</span>@if ($payDue) <span class="inline-block whitespace-nowrap {{ $debt->tone() }}">{{ $debt->word() }}</span>@elseif ($position) <x-route.term :position="$position" :waiting="false" idle class="inline-block"/>@endif</span>
    </td>
        @break
        @case('vendor')
    <td class="{{ $col->classes() }}"><x-vendor.ref :vendor-id="$offer->vendor_id" :ref="$offer->claim_ref" cell/></td>
        @break
        @case('what')
    <td class="{{ $col->classes() }}"><span class="whitespace-normal">{{ $what }}</span></td>
        @break
        @case('term')
    <td class="{{ $col->classes() }}">@if ($payDue)<span class="{{ $debt->tone() }}">{{ $debt->word() }}</span>@elseif ($position)<x-route.term :position="$position" :waiting="false" idle/>@endif</td>
        @break
        @case('amount')
    <td class="{{ $col->classes() }}">{{ $deal->isGarage() ? 'В гараж' : ($deal->amount === null ? '' : \App\Support\Money::rub($deal->amount)) }}</td>
        @break
        @endswitch
    @endforeach
</tr>
