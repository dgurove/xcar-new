{{-- Строка «Счетов и актов» (`ClosingDoc`): логотип и имя плательщика, под ним что за документ и сколько ТС (у отправленного —
     номер); файлы — чипами «Счёт» / «Акт» (шторка документов, скачать — в её полосе), на телефоне — вторым этажом; справа
     сумма, под ней на телефоне состояние словом — от 640 своим столбцом. Без плательщика (покупатель без контрагента)
     — строка без карточки: документа не из чего собрать. --}}
@props(['doc', 'table'])
@php
    use App\Support\Money;
    $d = $doc;
    $sub = $d->what.($d->what === 'хранение' || $d->what === 'эвакуация' || $d->what === 'страхователь' ? ', '.$d->cars.' ТС' : '');
@endphp
{{-- data-docs-scope — вкладки шторки документов только файлы этой строки. --}}
<tr @if ($d->state !== 'nobody') data-detail-key="{{ $d->key }}" @endif id="doc-{{ $d->key }}" data-docs-scope>
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('title')
    <td class="{{ $col->classes() }}">
        @if ($d->state !== 'nobody')
            <x-ui.row-link :key="$d->key"><span class="cell-title">@if ($d->vendor)<x-vendor.name :vendor="$d->vendor"/>@else{{ $d->name }}@endif</span></x-ui.row-link>
        @else
            <span class="cell-title">{{ $d->name }}</span>
        @endif
        <span class="cell-sub">
            <span class="sm:hidden {{ $d->tone() }}">{{ $d->word() }}</span>
            <span>{{ $sub }}</span>
            @if ($d->invoice)<span class="nums">{{ $d->invoice->label() }}</span>@endif
            @if ($d->extra)<span>дополнительно</span>@endif
        </span>
        @if ($files = $d->files())
            <span class="doc-chips sm:hidden">@foreach ($files as $f)<x-billing.doc-chip :file="$f"/>@endforeach</span>
        @endif
    </td>
        @break
        @case('files')
    <td class="{{ $col->classes() }}"><span class="doc-chips">@foreach ($d->files() as $f)<x-billing.doc-chip :file="$f"/>@endforeach</span></td>
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
