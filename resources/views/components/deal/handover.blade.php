{{-- Получение автомобиля в CRM (04.10.2026): кто забирает ТС у владельца и с кем говорить — те же факты, что менеджер
     видит на своей странице сделки (`Offers\Handover`). Владелец, телефон и адрес — поля предложения: письмо вендора
     заполняет их само (`TakeContactFromLetter`), здесь их правят. Кто забирает — та же шторка «Вывоз» маршрута.
     compact — карточка строки (раздел), иначе — карточка страницы сделки; lane — внутри дорожки «Вывоз» редактора
     (06.10.2026): без своего заголовка и «Кто забирает» — это «⋯» самой дорожки.
     Контакт — текстом, «Изменить / Готово» ставит на его место поля (07.10.2026, владелец: «слишком много полей, человек
     путается»; `edit_card_controller`, как ТС и цены редактора). Пусто — «Добавить», ошибка — сразу поля. --}}
@props(['deal', 'compact' => false, 'lane' => false])
@php
    $offer = $deal->offer;
    $handover = \App\Offers\Handover::for($deal);
    $sheet = $offer->position(\App\Workflow\Track::Service) && ! $offer->pickedUp();
    $keys = ['insured_name', 'insured_phone', 'inspection_address'];
    $empty = ! $offer->insured_name && ! $offer->insured_phone && ! $offer->inspection_address;
    $editing = $errors->hasAny($keys);
    $form = 'handover-'.$deal->id;
@endphp
@if ($deal->isActive() && ! $deal->isGarage())
    <section {{ $attributes->class([$compact ? '' : 'box']) }} data-controller="edit-card" data-edit-card-form-value="{{ $form }}" @if ($editing) data-edit-card-editing-value="true" @endif>
        <div class="flex items-start gap-3">
            <div class="flex min-w-0 flex-1 flex-wrap items-center gap-x-3 gap-y-1.5">
            @unless ($lane)<h2 class="{{ $compact ? 'detail-section !mb-0' : 'box-title' }}">Получение автомобиля</h2>@endunless
            @if ($handover->where)<span class="tag">{{ $handover->where === 'У вас' ? 'У менеджера' : $handover->where }}</span>@endif
            @if ($handover->buyerPicks)
                <x-ui.state :tone="$handover->open ? 'open' : 'muted'">{{ $handover->open ? 'забирает менеджер, ему видно' : 'забирает менеджер' }}</x-ui.state>
            @elseif ($offer->position(\App\Workflow\Track::Service))
                <x-ui.state tone="muted">забираем мы</x-ui.state>
            @endif
            </div>
            <span class="flex shrink-0 items-center gap-1">
                @if ($sheet && ! $lane)
                    <button type="button" class="btn btn-s btn-quiet" data-controller="emit" data-action="emit#send" data-emit-event-param="pickup-{{ $offer->number }}:open">Кто забирает</button>
                @endif
                <button type="button" class="edit-card-toggle" data-edit-card-target="button" data-action="edit-card#toggle" @if ($empty) data-idle="Добавить" @endif>{{ $editing ? 'Готово' : ($empty ? 'Добавить' : 'Изменить') }}</button>
            </span>
        </div>
        <div data-edit-card-target="view" @if ($editing) hidden @endif>
            @unless ($empty)<div class="mt-3 grid grid-cols-[minmax(0,1fr)_auto] gap-x-4 gap-y-2.5 leading-snug">
                @if ($offer->insured_name)<div class="min-w-0"><div class="field-label">Владелец</div><div class="mt-0.5 break-words">{{ $offer->insured_name }}</div></div>@endif
                @if ($offer->insured_phone)<div class="min-w-0"><div class="field-label">Телефон</div><a href="tel:{{ preg_replace('/[^\d+]/', '', $offer->insured_phone) }}" class="nums mt-0.5 block whitespace-nowrap text-accent-text">{{ $offer->insured_phone }}</a></div>@endif
                @if ($offer->inspection_address)<div class="col-span-2 min-w-0"><div class="field-label">Адрес</div><div class="mt-0.5 break-words">{{ $offer->inspection_address }}</div></div>@endif
            </div>@endunless
        </div>
        <div data-edit-card-target="edit" @unless ($editing) hidden @endunless>
            <form method="post" action="/offers/{{ $offer->number }}" id="{{ $form }}" class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2" data-controller="save-bar">
                @csrf @method('put')
                <x-ui.field name="insured_name" label="Владелец" :value="$offer->insured_name" id="handover-name-{{ $deal->id }}"/>
                <x-ui.field name="insured_phone" label="Телефон" :value="$offer->insured_phone" id="handover-phone-{{ $deal->id }}"/>
                <x-ui.field name="inspection_address" label="Адрес" :value="$offer->inspection_address" span="sm:col-span-2" id="handover-address-{{ $deal->id }}"/>
                <x-ui.save-bar class="sm:col-span-2" :page="! $compact"/>
            </form>
        </div>
    </section>
@endif
