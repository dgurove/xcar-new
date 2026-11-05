{{-- ТС документа строкой списка `.list` его страницы — сверка дат по актам (05.11.2026, владелец: «вот тачка, вот ставка, вот
     даты приёма и выдачи и вот можно кликнуть посмотреть акт приёма»; 06.11.2026 — «строки нормальные, а не таблица, которую
     вбок скроллить»): номер по порядку, название — в дело ТС, справа сумма; второй строкой № убытка, даты «04.02 → 31.10»
     (каждая — календарь на месте, `x-park.date-edit`, `SetDates`; сохранили — документ пересчитался) и «33 сут × 300»;
     под суммой — акт тихой меткой (`x-billing.registry-acts`), вкладки шторки — файлы этой строки (`data-docs-scope`). --}}
@props(['car', 'n', 'acts' => null])
@php
    use App\Support\Money; use App\Support\Ago;
    $v = $car['vehicle']; $edit = auth()->user()->parkDates() && $v->accepted_at;
    $rate = $car['rates'] ? implode(' → ', array_map(fn ($r) => Money::nums($r), $car['rates'])) : '';
@endphp
<div id="car-{{ $v->id }}" class="row doc-car-row" data-docs-scope>
    <span class="doc-car-n nums">{{ $n }}</span>
    <div class="min-w-0 flex-1">
        <a href="/cars/{{ $v->id }}" class="doc-car-name" data-turbo-frame="_top">{{ $v->titleWithYear() }}</a>
        @if ($car['extra'])<span class="doc-car-extra">{{ implode(', ', $car['extra']) }}</span>@endif
        <div class="row-sub">
            @if ($v->ref)<span class="nums">{{ $v->ref }}</span>@endif
            <span class="doc-car-dates nums">
                @if ($edit)
                    <x-park.date-edit :vehicle="$v" field="accepted_at" class="doc-date-btn"/>
                    <form method="post" action="/cars/{{ $v->id }}/dates" id="dates-{{ $v->id }}-accepted_at" hidden>@csrf</form>
                @elseif ($v->accepted_at){{ Ago::day($v->accepted_at) }}@endif
                <span aria-hidden="true">→</span>
                @if ($edit && $v->released_at)
                    <x-park.date-edit :vehicle="$v" field="released_at" class="doc-date-btn"/>
                    <form method="post" action="/cars/{{ $v->id }}/dates" id="dates-{{ $v->id }}-released_at" hidden>@csrf</form>
                @elseif ($v->released_at){{ Ago::day($v->released_at) }}@else стоит @endif
            </span>
            @if ($car['days'])<span class="nums">{{ $car['days'] }}&nbsp;сут@if ($rate) ×&nbsp;{{ $rate }}@endif</span>@endif
        </div>
    </div>
    <div class="doc-car-end">
        <span class="nums">{{ Money::rub($car['amount']) }}</span>
        <x-billing.registry-acts :acts="$acts ?? ['intake' => [], 'release' => [], 'other' => []]"/>
    </div>
</div>
