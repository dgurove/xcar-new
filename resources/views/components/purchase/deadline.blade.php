{{-- Срок закупки словом: идёт приём — до даты или обратный отсчёт (меньше суток — оранжевым), иначе «Приём закрыт». --}}
@props(['purchase'])
@if ($purchase->acceptsOffers() && $purchase->offers_close_at)
    <x-ui.state :tone="$purchase->offers_close_at->diffInHours(now(), true) < 24 ? 'urgent' : 'accent'" {{ $attributes }}>
        @if ($purchase->offers_close_at->isAfter(now()->addDay()))до {{ $purchase->offers_close_at->translatedFormat('j F, H:i') }} МСК
        @else осталось&nbsp;<span class="nums" data-controller="timer" data-timer-until-value="{{ $purchase->offers_close_at->toIso8601String() }}" data-timer-done-value="0:00" data-timer-coarse-value="true" data-timer-word-value="">{{ \App\Support\Ago::left($purchase->offers_close_at, '') }}</span>@endif
    </x-ui.state>
@elseif ($purchase->acceptsOffers())
    <x-ui.state tone="accent" {{ $attributes }}>приём цен</x-ui.state>
@else
    <x-ui.state tone="closed" {{ $attributes }}>приём закрыт@if ($purchase->offers_close_at) {{ $purchase->offers_close_at->translatedFormat('j F') }}@endif</x-ui.state>
@endif
