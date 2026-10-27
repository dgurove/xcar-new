{{-- Строка «Ваш ход» в «Сделках» CRM (27.10.2026, владелец: «не видно, что нужно сделать, а что просто просрочено»): что
     сделать — заголовок группы, здесь машина, вендор с убытком, менеджер, сколько ждёт (серым; срок вышел — красным
     «просрочено») и кнопка дела. Рыжего нет: вся группа — наш ход. --}}
@props(['deal', 'todo'])
@php
    $offer = $deal->offer;
    $position = $offer->position();
    $late = $position->isOverdue();
    $at = $late ? $position->deadline_at : $todo['since'];
    $wait = $at ? ($late ? 'просрочено ' : 'ждёт ').'<span class="nums" data-controller="timer" data-timer-since-value="'.$at->toIso8601String().'" data-timer-coarse-value="true"></span>' : null;
    $tone = $late ? 'text-danger' : 'text-ink-muted';
@endphp
<tr data-detail-key="{{ $deal->id }}" data-search-row id="deal-{{ $deal->id }}">
    <td class="grow">
        <x-ui.row-link :key="$deal->id"><span class="cell-title"><x-ui.cat-icon :category="$offer->category()"/>{{ $offer->titleWithYear() }}<x-car.keys :count="$offer->keys_count"/></span></x-ui.row-link>
        <span class="cell-sub sm:hidden"><x-vendor.ref :vendor-id="$offer->vendor_id" :ref="$offer->claim_ref"/>@if ($wait) <span class="inline-block whitespace-nowrap {{ $tone }}">{!! $wait !!}</span>@endif</span>
    </td>
    <td class="hidden sm:table-cell"><x-vendor.ref :vendor-id="$offer->vendor_id" :ref="$offer->claim_ref" cell/></td>
    <td class="hidden sm:table-cell">@if ($deal->buyer)<span class="flex items-center gap-2" title="{{ $deal->buyer->name }}"><x-ui.avatar :user="$deal->buyer" :size="22"/><span class="hidden truncate xl:inline">{{ $deal->buyer->name }}</span></span>@endif</td>
    <td class="fill hidden whitespace-nowrap sm:table-cell {{ $tone }}">{!! $wait !!}</td>
    <td class="num">@if ($todo['button'])<x-deal.todo-button :button="$todo['button']"/>@endif</td>
</tr>
