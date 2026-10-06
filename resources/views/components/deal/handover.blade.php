{{-- Получение автомобиля в CRM (04.10.2026): кто забирает ТС у владельца и с кем говорить — те же факты, что менеджер
     видит на своей странице сделки (`Offers\Handover`). Владелец, телефон и адрес — поля предложения: письмо вендора
     заполняет их само (`TakeContactFromLetter`), здесь их правят. Кто забирает — та же шторка «Вывоз» маршрута.
     compact — карточка строки (раздел), иначе — карточка страницы сделки; lane — внутри дорожки «Вывоз» редактора
     (06.10.2026): без своего заголовка и «Кто забирает» — это «⋯» самой дорожки. --}}
@props(['deal', 'compact' => false, 'lane' => false])
@php
    $offer = $deal->offer;
    $handover = \App\Offers\Handover::for($deal);
    $sheet = $offer->position(\App\Workflow\Track::Service) && ! $offer->pickedUp();
@endphp
@if ($deal->isActive() && ! $deal->isGarage())
    <section {{ $attributes->class([$compact ? '' : 'box']) }}>
        <div class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-1.5">
            @unless ($lane)<h2 class="{{ $compact ? 'detail-section !mb-0' : 'box-title' }}">Получение автомобиля</h2>@endunless
            @if ($handover->where)<span class="tag">{{ $handover->where === 'У вас' ? 'У менеджера' : $handover->where }}</span>@endif
            @if ($handover->buyerPicks)
                <x-ui.state :tone="$handover->open ? 'open' : 'muted'">{{ $handover->open ? 'забирает менеджер, ему видно' : 'забирает менеджер' }}</x-ui.state>
            @elseif ($offer->position(\App\Workflow\Track::Service))
                <x-ui.state tone="muted">забираем мы</x-ui.state>
            @endif
            @if ($sheet && ! $lane)
                <button type="button" class="btn btn-s btn-quiet ml-auto" data-controller="emit" data-action="emit#send" data-emit-event-param="pickup-{{ $offer->number }}:open">Кто забирает</button>
            @endif
        </div>
        <form method="post" action="/offers/{{ $offer->number }}" class="grid grid-cols-1 gap-3 sm:grid-cols-2" data-controller="save-bar">
            @csrf @method('put')
            <x-ui.field name="insured_name" label="Владелец" :value="$offer->insured_name" id="handover-name-{{ $deal->id }}"/>
            <x-ui.field name="insured_phone" label="Телефон" :value="$offer->insured_phone" id="handover-phone-{{ $deal->id }}"/>
            <x-ui.field name="inspection_address" label="Адрес" :value="$offer->inspection_address" span="sm:col-span-2" id="handover-address-{{ $deal->id }}"/>
            <x-ui.save-bar class="sm:col-span-2" :page="! $compact"/>
        </form>
    </section>
@endif
