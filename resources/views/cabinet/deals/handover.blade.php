{{-- Получение автомобиля (`Offers\Handover`) внутри задачи сделки — фактами одной строкой, а не строками-кнопками
     (05.10.2026, владелец: «У владельца» и «Самара» огромные, ссылка на карту не нужна): где стоит, кто забирает, место,
     когда; ниже контакт — имя и телефон капсулой, нажатие звонит. «Автомобиль забрал» вывоза без шага продажи (Альфа)
     ставит сам шаг. --}}
@php
    /** @var \App\Offers\Handover $handover */
    $who = $handover->withBuyer ? null : ($handover->buyerPicks ? 'забираете вы' : 'заберём сами');
@endphp
<div class="handover mt-4">
    <p class="handover-facts">
        @if ($handover->where)<span><x-ui.icon name="car" class="size-4"/><span>{{ $handover->where }}@if ($who)<span class="text-ink-muted">, {{ $who }}</span>@endif</span></span>@endif
        @if ($handover->address)<x-ui.place>{{ $handover->address }}</x-ui.place>@endif
        @if ($handover->dateLabel())<span class="nums"><x-ui.icon name="clock" class="size-4"/>{{ $handover->dateLabel() }}</span>@endif
    </p>
    @if ($handover->name || $handover->phone)
        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5">
            @if ($handover->name)<span class="min-w-0 break-words">{{ $handover->name }}</span>@endif
            @if ($handover->phone)<a href="tel:+{{ $handover->phone }}" class="call nums"><x-ui.icon name="phone" class="size-4"/>{{ $handover->phoneFormatted() }}</a>@endif
        </div>
    @endif
</div>
