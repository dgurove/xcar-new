{{-- Машина закупки в CRM: слева фото, название и факты чипами, справа — предложения менеджеров
     чипами-кнопками (x-purchase.offer-chip). highlight — id менеджера, чей чип идёт первым и ярким. --}}
@props(['car', 'purchase', 'highlight' => null])
@php
    use App\Purchases\{OfferState, ImportState};
    $n = $purchase->number;
    $offers = $car->activeOfferList()->sortBy([fn ($a, $b) => ($b->user_id === $highlight) <=> ($a->user_id === $highlight), fn ($a, $b) => $b->amount <=> $a->amount])->values();
    $amber = '--tag-bg:#fef3c7;--tag-text:#92400e;--tag-bg-d:#3f2606;--tag-text-d:#fcd34d';
@endphp
<div class="row flex-col items-stretch sm:flex-row sm:items-center sm:gap-x-6">
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
    <div class="flex min-w-0 flex-1 flex-wrap items-center gap-1.5 mt-2.5 empty:mt-0 sm:mt-0 sm:justify-end">
        @foreach ($offers as $offer)
            <x-purchase.offer-chip :offer="$offer" :car="$car" :highlight="$highlight"/>
        @endforeach
    </div>
</div>
