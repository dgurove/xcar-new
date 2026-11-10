{{-- Строка экрана «Горит» (`AlarmController`): название с годом, под ним на телефоне — логотип страховой и номер убытка (на ПК —
     своим столбцом). Этапа и менеджера нет (10.11.2026, владелец: что с машиной, и так говорит подзаголовок группы); справа — на сколько просрочен срок обязывающего
     (красным) или сколько осталось (оранжевым). Причина — подзаголовком группы, не здесь. В CRM строка открывает карточку
     рядом (`x-ui.row-link`), в логистике — задачу вывоза или предложение в CRM (`href`). --}}
@props(['offer', 'table', 'href' => null])
@php
    $until = $offer->binding_until;
    $past = $until->isPast();
    $term = $past ? \App\Support\Ago::overdue($until) : \App\Support\Ago::left($until);
@endphp
<tr data-detail-key="{{ $offer->number }}" data-search-row>
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('title')
    <td class="{{ $col->classes() }}">
        @if ($href)
            <a href="{{ $href }}" class="row-link" @if (str_starts_with($href, 'http')) data-turbo="false" @endif><span class="cell-title"><x-ui.cat-icon :category="$offer->category()"/>{{ $offer->titleWithYear() }}</span></a>
        @else
            <x-ui.row-link :key="$offer->number"><span class="cell-title"><x-ui.cat-icon :category="$offer->category()"/>{{ $offer->titleWithYear() }}</span></x-ui.row-link>
        @endif
        <span class="cell-sub sm:hidden"><x-vendor.ref :vendor-id="$offer->vendor_id" :ref="$offer->claim_ref"/></span>
    </td>
        @break
        @case('vendor')
    <td class="{{ $col->classes() }}"><x-vendor.ref :vendor-id="$offer->vendor_id" :ref="$offer->claim_ref" cell/></td>
        @break
        @case('term')
    <td class="{{ $col->classes() }}">
        <span @class(['whitespace-nowrap', 'text-danger' => $past, 'text-urgent' => ! $past])>{{ $term }}</span>
        <span class="cell-sub">{{ \App\Offers\Binding::html($until) }}</span>
    </td>
        @break
        @endswitch
    @endforeach
</tr>
