{{-- Машина закупки в CRM: слева фото, название и факты строкой текста (обе цены Carcade), справа — предложения
     менеджеров чипами-кнопками (x-purchase.offer-chip) и, если price, акцентный чип с нашей ценой / «оценить» — ссылка на
     экран оценки. highlight — id менеджера, чей чип идёт первым и ярким. --}}
@props(['car', 'purchase', 'highlight' => null, 'price' => false])
@php
    use App\Purchases\{OfferState, ImportState};
    $n = $purchase->number;
    $offers = $car->activeOfferList()->sortBy([fn ($a, $b) => ($b->user_id === $highlight) <=> ($a->user_id === $highlight), fn ($a, $b) => $b->amount <=> $a->amount])->values();
@endphp
<div class="row flex-col items-stretch sm:flex-row sm:items-center sm:gap-x-6">
    <div class="flex min-w-0 items-start gap-3">
        <a href="/purchases/{{ $n }}/{{ $car->ref }}" class="row-photo"><x-offer.photo :media="$car->mainPhoto()" sizes="64px"/></a>
        <div class="min-w-0 flex-1">
            <a href="/purchases/{{ $n }}/{{ $car->ref }}" class="block truncate">{{ $car->titleWithYear() }}</a>
            {{-- Факты одной строкой текста, как во второй строке таблицы: что не так — первым и цветом. --}}
            <div class="row-sub">
                @if ($car->specs_state->needsAttention())<span class="text-urgent">{{ mb_strtolower($car->specs_state->label()) }}</span>@elseif ($car->photos_state->needsAttention())<span class="text-urgent">{{ mb_strtolower($car->photos_state->label()) }}</span>@endif
                @if (in_array($car->photos_state, [ImportState::Pending, ImportState::Running], true))<span>фото едут</span>@endif
                @unless ($car->is_published)<span>скрыта</span>@endunless
                <span class="nums">{{ $car->dl }}</span>
                <span>{{ mb_strtolower($car->kind->label()) }}</span>
                @if ($car->settlement?->name ?? $car->city)<span>{{ $car->settlement?->name ?? $car->city }}</span>@endif
                @if ($car->price_listing)<span class="nums">размещение {{ \App\Support\Money::rub($car->price_listing) }}</span>@endif
                @if ($car->price_revalued)<span class="nums">переоценка {{ \App\Support\Money::rub($car->price_revalued) }}</span>@endif
            </div>
        </div>
    </div>
    <div class="flex min-w-0 flex-1 flex-wrap items-center gap-1.5 mt-2.5 empty:mt-0 sm:mt-0 sm:justify-end">
        @foreach ($offers as $offer)
            <x-purchase.offer-chip :offer="$offer" :car="$car" :highlight="$highlight"/>
        @endforeach
        @if ($price)
            <a href="/purchases/{{ $n }}?{{ $car->price_final ? '' : 'preset=unfinal&' }}vid=table&peek={{ $car->ref }}" class="chip nums whitespace-nowrap {{ $car->price_final ? 'bg-accent-soft text-accent-text' : '' }}">{{ $car->price_final ? \App\Support\Money::rub($car->price_final) : 'оценить' }}</a>
        @endif
    </div>
</div>
