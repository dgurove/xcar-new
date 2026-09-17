{{-- Окошко строки таблицы машин закупки (фрейм peek). --}}
@php
    use App\Purchases\ImportState;
    $n = $purchase->number;
    $href = "/purchases/{$n}/{$car->ref}";
    $offers = $car->activeOfferList()->sortByDesc('amount')->values();
    $amber = '--tag-bg:#fef3c7;--tag-text:#92400e;--tag-bg-d:#3f2606;--tag-text-d:#fcd34d';
@endphp
<turbo-frame id="peek" target="_top">
    <x-ui.peek :href="$href" :title="$car->titleWithYear()" :photo="$car->mainPhoto()" :facts="$car->facts()">
        <x-slot:marks>
            <span class="tag nums">{{ $car->dl }}</span>
            <span class="tag">{{ $car->kind->label() }}</span>
            @if ($car->settlement?->name ?? $car->city)<span class="tag">{{ $car->settlement?->name ?? $car->city }}</span>@endif
            @if ($car->vin)<span class="tag nums">{{ $car->vin }}</span>@endif
            @if ($car->price_revalued)<span class="tag nums">переоценка {{ \App\Support\Money::rub($car->price_revalued) }}</span>@endif
            @if ($car->price_listing)<span class="tag nums">размещение {{ \App\Support\Money::rub($car->price_listing) }}</span>@endif
            @if ($car->specs_state->needsAttention())<span class="tag" style="{{ $amber }}">{{ $car->specs_state->label() }}</span>@elseif ($car->photos_state->needsAttention())<span class="tag" style="{{ $amber }}">{{ $car->photos_state->label() }}</span>@endif
            @if (in_array($car->photos_state, [ImportState::Pending, ImportState::Running], true))<span class="tag">фото едут</span>@endif
            @unless ($car->is_published)<span class="tag">скрыта</span>@endunless
        </x-slot:marks>
        @if ($offers->isNotEmpty())
            <div class="mt-3 flex flex-wrap items-center gap-1.5">
                @foreach ($offers as $offer)<x-purchase.offer-chip :offer="$offer" :car="$car"/>@endforeach
            </div>
        @endif
        <x-slot:aside>
            <a href="{{ $href }}/estimate" class="chip nums whitespace-nowrap {{ $car->price_final ? 'bg-accent-soft text-accent-text font-bold' : '' }}">{{ $car->price_final ? \App\Support\Money::rub($car->price_final) : 'оценить' }}</a>
        </x-slot:aside>
    </x-ui.peek>
</turbo-frame>
