{{-- Строка «Счетов и актов» (`ClosingDoc`): логотип и имя плательщика, под ним что за документ и сколько ТС (у отправленного —
     номер); справа сумма, под ней на телефоне состояние словом — от 640 своим столбцом. Файлов в строке нет (владелец:
     «бесполезные») — они в карточке. Без плательщика (покупатель без контрагента)
     — строка без карточки: документа не из чего собрать. --}}
@props(['doc', 'table'])
@php
    use App\Support\Money;
    $d = $doc;
    $sub = $d->what.($d->what === 'хранение' || $d->what === 'эвакуация' || $d->what === 'страхователь' ? ', '.$d->cars.' ТС' : '');
@endphp
<tr id="doc-{{ $d->key }}" @class(['doc-row', 'is-link' => $d->state !== 'nobody'])>
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('title')
    <td class="{{ $col->classes() }}">
        @if ($d->state !== 'nobody')
            {{-- Ссылка на всю строку (`.doc-row.is-link a::after`): нажатие куда угодно — страница документа. --}}
            <a href="/money/closing/{{ $d->key }}" class="doc-row-link"><span class="cell-title">@if ($d->vendor)<x-vendor.name :vendor="$d->vendor"/>@else{{ $d->name }}@endif</span></a>
        @else
            <span class="cell-title">{{ $d->name }}</span>
        @endif
        <span class="cell-sub">
            <span class="sm:hidden {{ $d->tone() }}">{{ $d->word() }}</span>
            <span>{{ $sub }}</span>
            @if ($d->invoice)<span class="nums">{{ $d->invoice->label() }}</span>@endif
            @if ($d->extra)<span>дополнительно</span>@endif
        </span>
    </td>
        @break
        @case('amount')
    <td class="{{ $col->classes() }}">
        {{ Money::nums($d->drift['now'] ?? $d->amount) }}
        @if ($d->drift)<span class="cell-sub line-through">{{ Money::nums($d->drift['was']) }}</span>@endif
    </td>
        @break
        @case('state')
    <td class="{{ $col->classes() }} {{ $d->tone() }}">{{ $d->word() }}</td>
        @break
        @endswitch
    @endforeach
</tr>
