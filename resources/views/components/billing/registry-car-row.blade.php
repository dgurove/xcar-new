{{-- ТС документа строкой таблицы на его странице — сверка дат по актам (05.11.2026, владелец: «вот тачка, вот ставка, вот даты
     приёма и выдачи и вот можно кликнуть посмотреть акт приёма»): название — в дело ТС, под ним убыток; акты чипами
     (`IntakeActs`: «приём», «выдача»; акта по имени нет — «документы N», акт среди них) — шторка документов, на ПК панелью
     справа, таблица остаётся рядом, вкладки — только файлы строки (`data-docs-scope`); ставка; даты приёма и выдачи —
     календарь на месте (`x-park.date-edit`, `SetDates`), сохранили — документ пересчитался; сутки и сумма. --}}
@props(['car', 'table', 'acts' => null])
@php
    use App\Support\Money; use App\Support\Ago;
    $v = $car['vehicle']; $edit = auth()->user()->parkDates() && $v->accepted_at;
    $acts ??= ['intake' => [], 'release' => [], 'other' => []];
    $rate = $car['rates'] ? implode(' → ', array_map(fn ($r) => Money::nums($r), $car['rates'])) : null;
@endphp
<tr id="car-{{ $v->id }}" data-docs-scope>
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('title')
    <td class="{{ $col->classes() }}">
        <a href="/cars/{{ $v->id }}" class="cell-title" data-turbo-frame="_top">{{ $v->titleWithYear() }}</a>
        <span class="cell-sub">
            @if ($v->ref)<span class="sm:hidden">{{ $v->ref }}</span>@endif
            @if ($rate)<span class="nums sm:hidden">{{ $rate }}&nbsp;₽/сут</span>@endif
            @foreach ($car['extra'] as $t)<span>{{ $t }}</span>@endforeach
        </span>
        {{-- На телефоне столбца актов нет — чипы под названием. --}}
        <span class="mt-1 block sm:hidden"><x-billing.registry-acts :acts="$acts"/></span>
    </td>
        @break
        @case('ref')
    <td class="{{ $col->classes() }}">{{ $v->ref }}</td>
        @break
        @case('acts')
    <td class="{{ $col->classes() }}">
        <x-billing.registry-acts :acts="$acts"/>
    </td>
        @break
        @case('rate')
    <td class="{{ $col->classes() }}">{{ $rate }}</td>
        @break
        @case('accepted')
    <td class="{{ $col->classes() }}">
        @if ($edit)
            <x-park.date-edit :vehicle="$v" field="accepted_at" :time="$v->accepted_at->format('H:i') !== '00:00'" class="nums whitespace-nowrap underline decoration-dotted decoration-ink-dim underline-offset-4"/>
            <form method="post" action="/cars/{{ $v->id }}/dates" id="dates-{{ $v->id }}-accepted_at" hidden>@csrf</form>
        @else{!! Ago::html($v->accepted_at, words: false) !!}@endif
    </td>
        @break
        @case('released')
    <td class="{{ $col->classes() }}">
        @if ($edit && $v->released_at)
            <x-park.date-edit :vehicle="$v" field="released_at" :time="$v->released_at->format('H:i') !== '00:00'" class="nums whitespace-nowrap underline decoration-dotted decoration-ink-dim underline-offset-4"/>
            <form method="post" action="/cars/{{ $v->id }}/dates" id="dates-{{ $v->id }}-released_at" hidden>@csrf</form>
        @elseif ($v->released_at){!! Ago::html($v->released_at, words: false) !!}@else<span class="text-ink-dim">стоит</span>@endif
    </td>
        @break
        @case('days')
    <td class="{{ $col->classes() }}">{{ $car['days'] ?: '' }}</td>
        @break
        @case('amount')
    <td class="{{ $col->classes() }}">{{ Money::nums($car['amount']) }}</td>
        @break
        @endswitch
    @endforeach
</tr>
