{{-- Машина закупки в CRM: слева фото, название и факты чипами, справа — предложения менеджеров
     по одному в строке (человек, цена, разница к нашей, выбрать/отменить). highlight — id менеджера,
     чья цена идёт первой и выделена. --}}
@props(['car', 'purchase', 'highlight' => null])
@php
    use App\Purchases\{OfferState, ImportState};
    $n = $purchase->number;
    $offers = $car->activeOfferList()->sortBy([fn ($a, $b) => ($b->user_id === $highlight) <=> ($a->user_id === $highlight), fn ($a, $b) => $b->amount <=> $a->amount])->values();
    $amber = '--tag-bg:#fef3c7;--tag-text:#92400e;--tag-bg-d:#3f2606;--tag-text-d:#fcd34d';
    $lime = '--tag-bg:#f0f7d8;--tag-text:#669709;--tag-bg-d:#1a2605;--tag-text-d:#a6cf3a';
@endphp
<div class="row flex-col items-stretch sm:grid sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] sm:gap-x-6">
    <div class="flex min-w-0 items-start gap-3">
        <a href="/zakupki/{{ $n }}/{{ $car->ref }}" class="row-photo"><x-offer.photo :media="$car->mainPhoto()" sizes="64px"/></a>
        <div class="min-w-0 flex-1">
            <a href="/zakupki/{{ $n }}/{{ $car->ref }}" class="flex items-baseline gap-2"><span class="truncate font-medium">{{ $car->titleWithYear() }}</span><span class="nums shrink-0 text-sm text-ink-dim">{{ $car->dl }}</span></a>
            <div class="mt-1.5 flex flex-wrap gap-1.5">
                @if ($car->price_listing)<span class="tag nums whitespace-nowrap">{{ number_format($car->price_listing, 0, '', ' ') }} ₽</span>@endif
                <span class="tag">{{ $car->kind->label() }}</span>
                @if ($car->settlement?->name ?? $car->city)<span class="tag">{{ $car->settlement?->name ?? $car->city }}</span>@endif
                @if ($car->specs_state->needsAttention())<span class="tag" style="{{ $amber }}">{{ $car->specs_state->label() }}</span>@elseif ($car->photos_state->needsAttention())<span class="tag" style="{{ $amber }}">{{ $car->photos_state->label() }}</span>@endif
                @if (in_array($car->photos_state, [ImportState::Pending, ImportState::Running], true))<span class="tag">фото едут</span>@endif
                @unless ($car->is_published)<span class="tag">скрыта</span>@endunless
            </div>
        </div>
    </div>
    <div class="mt-3 flex min-w-0 flex-col gap-1.5 sm:mt-0">
        @forelse ($offers as $offer)
            @php $chosen = $offer->state === OfferState::Chosen; $mine = $highlight && $offer->user_id === $highlight; @endphp
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1 {{ $highlight && ! $mine ? 'opacity-60' : '' }}">
                <x-ui.person :user="$offer->user" :current="$chosen || $mine" class="shrink-0"/>
                <span class="nums shrink-0 font-semibold {{ $chosen ? 'text-accent-text' : '' }}">{{ number_format($offer->amount, 0, '', ' ') }} ₽</span>
                @if ($car->price_listing)<span class="nums shrink-0 text-sm text-ink-dim">{{ $offer->amount >= $car->price_listing ? '+' : '−' }}{{ number_format(abs($offer->amount - $car->price_listing), 0, '', ' ') }}</span>@endif
                <span class="ml-auto flex shrink-0 items-center gap-1.5">
                    @if ($chosen)
                        <span class="tag" style="{{ $lime }}">выбрана</span>
                        <form method="post" action="/zakupki/ceny/{{ $offer->id }}/otmenit" data-turbo-confirm="Отменить выбор? Остальные цены по машине снова будут ждать">@csrf<button type="submit" class="btn btn-xs btn-quiet btn-round" aria-label="Отменить выбор"><x-ui.icon name="x" class="size-4"/></button></form>
                    @else
                        <form method="post" action="/zakupki/ceny/{{ $offer->id }}/vybrat">@csrf<button type="submit" class="btn btn-xs btn-quiet">Выбрать</button></form>
                    @endif
                </span>
            </div>
            @if ($offer->comment)<div class="-mt-1 pl-1 text-sm text-ink-muted">{{ $offer->comment }}</div>@endif
        @empty
            <span class="text-sm text-ink-dim">нет предложений</span>
        @endforelse
    </div>
</div>
