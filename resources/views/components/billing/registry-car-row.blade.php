{{-- ТС документа строкой таблицы его страницы — сверка дат по актам (05.11.2026, владелец: «вот тачка, вот ставка, вот даты
     приёма и выдачи и вот можно кликнуть посмотреть акт приёма»): №, название — в дело ТС, убыток целиком, даты одним видом
     (днём; время — в календаре) — нажатие открывает календарь на месте (`x-park.date-edit`, `SetDates`), сохранили —
     документ пересчитался; сутки, ставка, сумма; акт — одна кнопка, вкладки шторки — файлы этой строки (`data-docs-scope`). --}}
@props(['car', 'n', 'acts' => null])
@php
    use App\Support\Money; use App\Support\Ago;
    $v = $car['vehicle']; $edit = auth()->user()->parkDates() && $v->accepted_at;
    $rate = $car['rates'] ? implode(' → ', array_map(fn ($r) => Money::nums($r), $car['rates'])) : '';
@endphp
<tr id="car-{{ $v->id }}" data-docs-scope>
    <td class="doc-n nums">{{ $n }}</td>
    <td class="doc-car">
        <a href="/cars/{{ $v->id }}" class="doc-car-name" data-turbo-frame="_top">{{ $v->titleWithYear() }}</a>
        @if ($car['extra'])<span class="doc-car-extra">{{ implode(', ', $car['extra']) }}</span>@endif
    </td>
    <td class="doc-act"><x-billing.registry-acts :acts="$acts ?? ['intake' => [], 'release' => [], 'other' => []]"/></td>
    <td class="doc-code">{{ $v->ref }}</td>
    <td class="doc-date">
        @if ($edit)
            <x-park.date-edit :vehicle="$v" field="accepted_at" class="doc-date-btn nums"/>
            <form method="post" action="/cars/{{ $v->id }}/dates" id="dates-{{ $v->id }}-accepted_at" hidden>@csrf</form>
        @elseif ($v->accepted_at)<span class="nums">{{ Ago::day($v->accepted_at) }}</span>@endif
    </td>
    <td class="doc-date">
        @if ($edit && $v->released_at)
            <x-park.date-edit :vehicle="$v" field="released_at" class="doc-date-btn nums"/>
            <form method="post" action="/cars/{{ $v->id }}/dates" id="dates-{{ $v->id }}-released_at" hidden>@csrf</form>
        @elseif ($v->released_at)<span class="nums">{{ Ago::day($v->released_at) }}</span>@else<span class="text-ink-dim">стоит</span>@endif
    </td>
    <td class="num nums">{{ $car['days'] ?: '' }}</td>
    <td class="num nums">{{ $rate }}</td>
    <td class="num nums">{{ Money::nums($car['amount']) }}</td>
</tr>
