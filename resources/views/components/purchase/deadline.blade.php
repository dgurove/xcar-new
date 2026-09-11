{{-- Срок закупки пилюлей: идёт приём — до даты или обратный отсчёт, иначе «Приём закрыт». --}}
@props(['purchase'])
@if ($purchase->acceptsOffers() && $purchase->offers_close_at)
    <x-ui.pill :tone="$purchase->offers_close_at->diffInHours(now(), true) < 24 ? 'urgent' : 'accent'" {{ $attributes }}>
        @if ($purchase->offers_close_at->isAfter(now()->addDay()))до {{ $purchase->offers_close_at->translatedFormat('j F, H:i') }} МСК
        @else осталось <span class="nums" data-controller="timer" data-timer-until-value="{{ $purchase->offers_close_at->toIso8601String() }}" data-timer-done-value="0"></span>@endif
    </x-ui.pill>
@elseif ($purchase->acceptsOffers())
    <x-ui.pill tone="accent" {{ $attributes }}>Приём цен</x-ui.pill>
@else
    <x-ui.pill tone="closed" {{ $attributes }}>Приём закрыт@if ($purchase->offers_close_at) {{ $purchase->offers_close_at->translatedFormat('j F') }}@endif</x-ui.pill>
@endif
