{{-- Строка «Ваш ход» в «Сделках» CRM (27.10.2026, владелец: «не видно, что нужно сделать, а что просто просрочено»): что
     сделать — заголовок группы, здесь машина, вендор с убытком, менеджер, сколько ждёт (серым; срок вышел — красным
     «просрочено») и кнопка дела. Рыжего нет: вся группа — наш ход. Столбцы — `DealController::table('us')`. --}}
@props(['deal', 'todo', 'table'])
@php
    $offer = $deal->offer;
    $position = $offer->position();
    $late = $position->isOverdue();
    $at = $late ? $position->deadline_at : $todo['since'];
    $wait = $at ? ($late ? 'просрочено ' : 'ждёт ').'<span class="nums" data-controller="timer" data-timer-since-value="'.$at->toIso8601String().'" data-timer-coarse-value="true"></span>' : null;
    $tone = $late ? 'text-danger' : 'text-ink-muted';
@endphp
<tr data-detail-key="{{ $deal->id }}" data-search-row id="deal-{{ $deal->id }}">
    <td class="{{ $table->cls('title') }}">
        <x-cell.title :key="$deal->id" :offer="$offer"/>
        <span class="cell-sub sm:hidden"><x-vendor.ref :vendor-id="$offer->vendor_id" :ref="$offer->claim_ref"/>@if ($wait) <span class="inline-block whitespace-nowrap {{ $tone }}">{!! $wait !!}</span>@endif</span>
    </td>
    <td class="{{ $table->cls('vendor') }}"><x-vendor.ref :vendor-id="$offer->vendor_id" :ref="$offer->claim_ref" cell/></td>
    <td class="{{ $table->cls('manager') }}"><x-cell.person :user="$deal->buyer" from="xl"/></td>
    <td class="{{ $table->cls('wait', 'whitespace-nowrap '.$tone) }}">{!! $wait !!}</td>
    <td class="{{ $table->cls('action') }}">@if ($todo['button'])<x-deal.todo-button :button="$todo['button']"/>@endif</td>
</tr>
