{{-- Строка «Счетов и актов» (`ClosingDoc`) в таблице кита: маленький логотип и имя плательщика, под ним что за документ (у
     отправленного — номер; на телефоне ещё сколько ТС и состояние); на ПК — за что, состояние, отправлен, оплатить до, число строк
     акта; справа сумма. Файлов в строке нет (владелец: «убери») — они на странице документа. Нажатие по
     строке — страница документа (`row-go`, ссылка `a.row-link` у имени). Без плательщика (покупатель без контрагента) —
     строка без ссылки: документа не из чего собрать. --}}
@props(['doc', 'table'])
@php
    use App\Support\Money; use App\Support\Ago;
    $d = $doc;
    $counted = in_array($d->what, ['хранение', 'эвакуация', 'страхователь'], true);
    $i = $d->invoice;
@endphp
{{-- `data-detail-key` — по нему сервер считает ширины столбцов (`Support\Table\Widths`); окошка у списка нет. --}}
<tr id="doc-{{ $d->key }}" data-detail-key="{{ $d->key }}">
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('title')
    <td class="{{ $col->classes() }}">
        @if ($d->state !== 'nobody')
            <a href="/money/closing/{{ $d->key }}" class="row-link"><span class="cell-title">@if ($d->vendor)<x-vendor.name :vendor="$d->vendor"/>@else{{ $d->name }}@endif</span></a>
        @else
            <span class="cell-title">{{ $d->name }}</span>
        @endif
        <span class="cell-sub">
            <span class="sm:hidden {{ $d->tone() }}">{{ $d->word() }}</span>
            {{-- На ПК «за что» и число строк — своими столбцами; на телефоне — здесь: хранение словом не пишется (почти все
                 счета такие), другое — пишется; число строк акта (владелец: «не N ТС, а N строк»). --}}
            @if ($d->what !== 'хранение')<span class="sm:hidden">{{ $d->what }}</span>@endif
            @if ($counted)<span class="sm:hidden">{{ $d->cars }}&nbsp;{{ \App\Support\Plural::of($d->cars, ['строка', 'строки', 'строк']) }}</span>@endif
            @if ($d->invoice)<span class="nums">{{ $d->invoice->label() }}</span>@endif
            @if ($d->extra)<span>дополнительно</span>@endif
        </span>
    </td>
        @break
        @case('what')
    <td class="{{ $col->classes() }}">{{ $d->what }}</td>
        @break
        @case('sent')
    <td class="{{ $col->classes() }}">@if ($i?->sent_at){{ Ago::day($i->sent_at) }}@endif</td>
        @break
        @case('due')
    <td class="{{ $col->classes() }} {{ $d->state === 'overdue' ? 'text-danger' : '' }}">@if ($i?->due_at){{ Ago::day($i->due_at) }}@endif</td>
        @break
        @case('cars')
    <td class="{{ $col->classes() }}">@if ($counted){{ $d->cars }}@endif</td>
        @break
        @case('state')
    <td class="{{ $col->classes() }} {{ $d->tone() }}">{{ $d->word() }}</td>
        @break
        @case('amount')
    <td class="{{ $col->classes() }}">
        {{ Money::rub($d->drift['now'] ?? $d->amount) }}
        @if ($d->drift)<span class="cell-sub line-through">{{ Money::rub($d->drift['was']) }}</span>@endif
    </td>
        @break
        @endswitch
    @endforeach
</tr>
