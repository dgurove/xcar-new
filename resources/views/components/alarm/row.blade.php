{{-- Строка экрана «Горит» (`AlarmController`): название с годом и номер убытка; ниже — этап: у сделки шаг маршрута продажи
     и менеджер, у остальных — вкладка, где предложение (`Offer::crmTab`); справа — на сколько просрочен срок обязывающего
     (красным) или сколько осталось (оранжевым). Причина — подзаголовком группы, не здесь. В CRM строка открывает карточку
     рядом (`x-ui.row-link`), в логистике — задачу вывоза или предложение в CRM (`href`). --}}
@props(['offer', 'table', 'href' => null])
@php
    $until = $offer->binding_until;
    $past = $until->isPast();
    $deal = $offer->deal;
    $stage = $deal ? $offer->position()?->stage : null;
    $step = $stage ? ($stage->block?->name ?? $stage->name) : $offer->crmTab();
    $term = $past ? \App\Support\Ago::overdue($until) : \App\Support\Ago::left($until);
    $who = $deal?->buyer?->shortName();
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
        <span class="cell-sub sm:hidden">{{ $step }}@if ($who), {{ $who }}@endif</span>
    </td>
        @break
        @case('step')
    {{-- Этап и менеджер — своими столбцами от 640 (во втором этаже названия этап резался). --}}
    <td class="{{ $col->classes() }}"><span class="block max-w-60 truncate">{{ $step }}</span></td>
        @break
        @case('manager')
    <td class="{{ $col->classes() }}">@if ($deal?->buyer)<span class="flex items-center gap-2" title="{{ $deal->buyer->name }}"><x-ui.avatar :user="$deal->buyer" :size="22"/><span class="truncate">{{ $who }}</span></span>@endif</td>
        @break
        @case('vendor')
    <td class="{{ $col->classes() }}"><x-vendor.ref :vendor-id="$offer->vendor_id" :ref="$offer->claim_ref" cell/></td>
        @break
        @case('term')
    <td class="{{ $col->classes() }}">
        <span @class(['whitespace-nowrap', 'text-danger' => $past, 'text-urgent' => ! $past])>{{ $term }}</span>
        <span class="cell-sub">{{ \App\Offers\Binding::date($until) }}</span>
    </td>
        @break
        @endswitch
    @endforeach
</tr>
