{{-- Строка перевозки: название машины, под ним ход перевозки словом (на телефоне с ним — кто везёт и маршрут); маршрут
     регионами (от 640), исполнитель (от 1024), справа — дата: отправка или прибытие у межрегиона, иначе когда назначили, забрали, сдали. --}}
@props(['t'])
@php
    $when = $t->shownAt();
    // На телефоне столбцов нет — кто везёт и маршрут дописываются к ходу перевозки.
    // Себя исполнитель в строке не видит.
    $who = $t->executor_id === auth()->id() ? null : $t->executorName();
    $rest = implode(', ', array_filter([$who, $t->regions()]));
@endphp
<tr data-detail-key="{{ $t->id }}" id="transport-{{ $t->id }}">
    <td class="grow">
        <x-ui.row-link :key="$t->id"><span class="cell-title"><x-ui.cat-icon :category="$t->category()"/><span class="cell-name">{{ $t->title() }}</span></span></x-ui.row-link>
        <span class="cell-sub"><span><span class="{{ $t->state->tone() }}">{{ mb_strtolower($t->state->label()) }}</span>@if ($rest)<span class="sm:hidden">, {{ $rest }}</span>@endif</span></span>
    </td>
    <td class="hidden sm:table-cell cell-dim">{{ $t->regions() }}</td>
    <td class="hidden lg:table-cell cell-dim">{{ $who }}</td>
    <td class="num nums cell-dim">{{ $when?->translatedFormat('j M') }}</td>
</tr>
