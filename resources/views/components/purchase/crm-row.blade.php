{{-- Машина закупки в CRM: фото, название, наша цена и все живые предложения с кнопками выбора.
     highlight — id менеджера, чья цена идёт первой и жирной. --}}
@props(['car', 'purchase', 'highlight' => null])
@php
    use App\Purchases\{OfferState, ImportState};
    $n = $purchase->number;
    $offers = $car->activeOfferList()->sortBy([fn ($a, $b) => ($b->user_id === $highlight) <=> ($a->user_id === $highlight), fn ($a, $b) => $b->amount <=> $a->amount])->values();
    $bad = $car->specs_state->needsAttention() || $car->photos_state->needsAttention();
@endphp
<div class="row items-start">
    <a href="/zakupki/{{ $n }}/{{ $car->ref }}" class="row-photo"><x-offer.photo :media="$car->mainPhoto()" sizes="64px"/></a>
    <div class="min-w-0 flex-1">
        <a href="/zakupki/{{ $n }}/{{ $car->ref }}" class="flex items-baseline gap-2"><span class="truncate font-medium">{{ $car->titleWithYear() }}</span><span class="shrink-0 text-sm text-ink-dim">{{ $car->dl }}</span></a>
        <div class="text-sm text-ink-muted">{{ implode(' · ', array_filter([$car->price_listing ? number_format($car->price_listing, 0, '', ' ').' ₽' : null, $car->kind->label(), $car->settlement?->name ?? $car->city])) }}</div>
        @if ($bad || in_array($car->photos_state, [ImportState::Pending, ImportState::Running], true) || ! $car->is_published)
            <div class="mt-1 flex flex-wrap items-center gap-1.5 text-sm">
                @if ($bad)<x-ui.pill tone="urgent" class="!min-h-0 !py-1 text-xs">{{ $car->specs_state->needsAttention() ? $car->specs_state->label() : $car->photos_state->label() }}</x-ui.pill>@endif
                @if (in_array($car->photos_state, [ImportState::Pending, ImportState::Running], true))<span class="chip">фото едут</span>@endif
                @unless ($car->is_published)<x-ui.pill tone="closed" class="!min-h-0 !py-1 text-xs">скрыта</x-ui.pill>@endunless
            </div>
        @endif
        @if ($offers->isEmpty())
            <div class="mt-1 text-sm text-ink-dim">нет предложений</div>
        @else
            <div class="mt-1.5 flex flex-col gap-1">
                @foreach ($offers as $offer)
                    @php $chosen = $offer->state === OfferState::Chosen; $mine = $highlight && $offer->user_id === $highlight; @endphp
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-sm {{ $highlight && ! $mine ? 'text-ink-muted' : '' }}">
                        <span class="nums {{ $mine || ! $highlight ? 'font-semibold' : '' }} {{ $chosen ? 'text-accent-text' : '' }}">{{ number_format($offer->amount, 0, '', ' ') }} ₽</span>
                        @if ($car->price_listing)<span class="text-ink-dim tabular-nums">{{ $offer->amount >= $car->price_listing ? '+' : '−' }}{{ number_format(abs($offer->amount - $car->price_listing), 0, '', ' ') }}</span>@endif
                        <span>{{ $offer->user->shortName() }}</span>
                        <span class="text-ink-dim">{{ $offer->created_at->translatedFormat('j M, H:i') }}</span>
                        @if ($chosen)
                            <span class="text-accent-text">выбрана</span>
                            <form method="post" action="/zakupki/ceny/{{ $offer->id }}/otmenit" data-turbo-confirm="Отменить выбор? Остальные цены по машине снова будут ждать">@csrf<button type="submit" class="text-ink-dim underline-offset-2 hover:underline">отменить</button></form>
                        @else
                            <form method="post" action="/zakupki/ceny/{{ $offer->id }}/vybrat">@csrf<button type="submit" class="text-accent-text underline-offset-2 hover:underline">выбрать</button></form>
                        @endif
                        @if ($offer->comment)<span class="basis-full text-ink-muted">{{ $offer->comment }}</span>@endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
