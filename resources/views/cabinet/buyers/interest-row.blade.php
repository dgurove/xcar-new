{{-- Интерес покупателя строкой: машина, кто, комментарий; смахивание — «Связались» / «Закрыть». --}}
@php $offer = $interest->offer; $main = $offer->mainPhoto(); $person ??= true; @endphp
<x-ui.swipe id="interest-{{ $interest->id }}">
    <a href="/offers/{{ $offer->number }}" class="row">
        <span class="row-photo"><x-offer.photo :media="$main" sizes="72px"/></span>
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                <span class="truncate font-medium">{{ $offer->titleWithYear() }}</span>
                @if ($interest->state !== \App\Offers\InterestState::New)<x-ui.pill tone="soft" class="!min-h-0 !py-0.5 text-xs">{{ $interest->state->label() }}</x-ui.pill>@endif
            </div>
            <div class="mt-1 flex flex-wrap items-center gap-1.5">
                @if ($person)<x-ui.person :user="$interest->user"/>@endif
                <span class="tag nums">{{ $interest->created_at->translatedFormat($interest->created_at->isToday() ? 'H:i' : 'j M, H:i') }}</span>
            </div>
            @if ($interest->comment)<p class="mt-1.5 text-sm text-ink-muted">{{ $interest->comment }}</p>@endif
        </div>
        @if ($offer->asking_price)<span class="nums shrink-0 text-sm">{{ number_format($offer->asking_price, 0, '', ' ') }} ₽</span>@endif
    </a>
    <x-slot:actions>
        @if ($interest->state === \App\Offers\InterestState::New)
            <form method="post" action="/lk/interes/{{ $interest->id }}" data-queue>@csrf<input type="hidden" name="state" value="contacted"><input type="hidden" name="row" value="1"><button type="submit" class="swipe-btn swipe-btn-accent" aria-label="Связались"><x-ui.icon name="check" class="size-5"/></button></form>
        @else
            <form method="post" action="/lk/interes/{{ $interest->id }}" data-queue>@csrf<input type="hidden" name="state" value="closed"><input type="hidden" name="row" value="1"><button type="submit" class="swipe-btn" aria-label="Закрыть"><x-ui.icon name="x" class="size-5"/></button></form>
        @endif
    </x-slot:actions>
</x-ui.swipe>
