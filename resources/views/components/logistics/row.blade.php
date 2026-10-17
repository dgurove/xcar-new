{{-- Строка вывоза (checkable — галочка пачки админа на «Без исполнителя»): машина и «!» за ключи, под ней что дальше цветом (просрочено — красным) и, на телефоне, кто везёт и
     город; от 640 — город, от 1024 — кто вывозит; справа дата: вывоз по созвону, забрали, привезли или когда назначили. --}}
@props(['t', 'checkable' => false])
@php
    $when = $t->shownAt();
    $me = auth()->user();
    // Себя исполнитель в строке не видит.
    $who = $t->isExecutedBy($me) ? null : $t->executorName();
    $city = $t->city();
    $rest = implode(', ', array_filter([$who, $city]));
    $late = $t->isLate();
@endphp
<tr data-detail-key="{{ $t->id }}" id="transport-{{ $t->id }}">
    @if ($checkable)<td class="pick-cell"><label class="row-check" aria-label="Выбрать"><span class="check"><input type="checkbox" id="pick-transport-{{ $t->id }}" data-turbo-permanent name="transports[]" value="{{ $t->id }}" data-pick-target="box" data-action="pick#sync"></span></label></td>@endif
    <td class="grow">
        <x-ui.row-link :key="$t->id"><span class="cell-title"><x-ui.cat-icon :category="$t->category()"/><span class="cell-name">{{ $t->title() }}</span><x-car.keys :count="$t->car?->keys_count"/></span></x-ui.row-link>
        <span class="cell-sub"><span><span class="{{ $late ? 'text-danger' : $t->state->tone() }}">{{ $late ? 'не вывезли в срок' : $t->next() }}</span>@if ($rest)<span class="sm:hidden">, {{ $rest }}</span>@endif</span></span>
    </td>
    <td class="hidden sm:table-cell cell-dim">{{ $city }}</td>
    <td class="hidden lg:table-cell cell-dim">{{ $who }}</td>
    <td class="num nums cell-dim {{ $late ? 'text-danger' : '' }}">{{ $when?->translatedFormat('j M') }}</td>
</tr>
