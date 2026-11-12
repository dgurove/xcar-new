{{-- Полоска срока в окошке ТС (09.11.2026, вариант D3 владельца): плашка — где стоит, сумма, линия от приёма до выдачи
     (у стоящей — до «сегодня»), под ней дата приёма, дни и дата выдачи. Даты — календарь по нажатию тем, кто правит даты
     (`parkDates`: `x-park.accepted`, `x-park.date-edit`), остальным — текстом. Парковка стоящей — кнопка «Переставить».
     Сумма — только видящим деньги, без слов рядом («не выставлен», долг и ставку владелец рядом с суммой не хочет):
     нажатие — шторка «Хранение» (`x-park.storage-sheet`: расчёт, ставка или своя сумма, «Выставить счёт»).
     Ожидается — пунктир «ждём приёма»; выдана — «выдана (98 д)» посередине; отменена — «отменена: причина». Состояние живёт
     только здесь — ни у названия, ни посередине полосы окна (12.11.2026, владелец). --}}
@props(['vehicle', 'total' => null, 'debt' => 0, 'unbilled' => 0, 'movable' => true, 'light' => false])
@php
    use App\Park\VehicleState;
    $state = $vehicle->state;
    $stored = $state === VehicleState::Stored;
    $out = $state === VehicleState::Released && $vehicle->released_at;
    $on = ($stored || $out) && $vehicle->accepted_at;
    $edit = auth()->user()->parkDates();
    $money = auth()->user()->parkMoney() && $total && ($total['amount'] ?? 0) > 0;
    $days = $on ? $vehicle->daysStored() : null;
    $sheet = 'storage-'.$vehicle->id;
@endphp
{{-- `light` — белая на сером фоне страницы дела. --}}
<div {{ $attributes->class(['stay', 'stay--light' => $light, 'stay--nomoney' => ! $money]) }}>
    <div class="stay-where">
        @if ($vehicle->yard && $stored && $movable)
            <x-park.place-move :vehicle="$vehicle" :chevron="false" class="stay-place underline decoration-dotted decoration-ink-dim underline-offset-4"/>
        @elseif ($vehicle->yard)
            <x-ui.place class="stay-place">{{ $vehicle->yard->name }}{{ $stored && $vehicle->spot ? ', '.$vehicle->spot : '' }}</x-ui.place>
        @else
            <span class="stay-place text-urgent">Нет парковки</span>
        @endif
    </div>
    @if ($money)
        <div class="stay-money" data-controller="sheet">
            <button type="button" class="stay-sum nums" data-action="sheet#open">{{ \App\Support\Money::rub($total['amount']) }}<x-ui.icon name="chevron-right" class="size-4 text-ink-dim"/></button>
            <x-park.storage-sheet :id="$sheet" :vehicle="$vehicle" :total="$total" :debt="$debt" :unbilled="$unbilled"/>
        </div>
    @endif
    <div class="stay-term">
        @if ($on)
            <div class="stay-track" aria-hidden="true"><span class="stay-line"></span><span class="stay-dot"></span><span class="stay-dot {{ $out ? '' : 'is-now' }}"></span></div>
            <div class="stay-dates">
                <span><x-park.accepted :vehicle="$vehicle" :class="$edit ? 'nums underline decoration-dotted decoration-ink-dim underline-offset-4' : 'nums'"/></span>
                {{-- Состояние — на шкале (12.11.2026, владелец: «писал бы просто „выдана (98 д)“ на шкале»): у стоящей — дни, у выданной —
                     «выдана (98 д)»; слова у названия и посередине полосы окна больше нет. --}}
                @if ($days !== null)<span class="stay-days nums">@if ($out)выдана ({{ $days }}&nbsp;д)@else{{ $days }} {{ \App\Support\Plural::of($days, ['день', 'дня', 'дней']) }}@endif</span>@endif
                <span class="text-right">
                    @if (! $out)
                        сегодня
                    @elseif ($edit)
                        <x-park.date-edit :vehicle="$vehicle" field="released_at" class="nums underline decoration-dotted decoration-ink-dim underline-offset-4"/>
                    @else
                        <span class="nums">{{ \App\Support\Ago::day($vehicle->released_at) }}</span>
                    @endif
                </span>
            </div>
        @elseif ($state === VehicleState::Cancelled)
            <div class="text-sm text-ink-muted">отменена@if ($vehicle->cancel_reason): {{ $vehicle->cancel_reason }}@endif</div>
        @elseif ($state->isBefore())
            <div class="stay-track" aria-hidden="true"><span class="stay-line is-wait"></span><span class="stay-dot is-hollow"></span><span class="stay-dot is-hollow"></span></div>
            <div class="stay-dates"><span>ждём приёма</span></div>
        @endif
    </div>
</div>
