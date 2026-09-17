{{-- Окошко строки таблицы машин закупки на сайте (фрейм peek). --}}
@php
    $staff = auth()->user()?->isStaff() ?? false;
    $mine = $staff ? null : $car->offerOf(auth()->user());
    $best = $staff ? $car->bestOffer() : null;
    $href = "/purchases/{$purchase->number}/{$car->ref}".($query ? '?'.$query : '');
@endphp
<turbo-frame id="peek" target="_top">
    <x-ui.peek :href="$href" :title="$car->titleWithYear()" :photo="$car->mainPhoto()" :facts="$car->facts()" :action="$staff || $mine ? 'Открыть' : 'Предложить'">
        <x-slot:marks>
            <span class="tag nums">№ {{ $car->ref }}</span>
            <span class="tag">{{ $car->kind->label() }}</span>
            @if ($car->settlement?->name ?? $car->city)<span class="tag">{{ $car->settlement?->name ?? $car->city }}</span>@endif
            @if ($car->vin)<span class="tag nums">{{ $car->vin }}</span>@endif
            @if ($car->fssp)<span class="tag">Ограничения ФССП</span>@endif
        </x-slot:marks>
        <x-slot:aside>
            @if ($staff && $best)<span class="nums text-lg font-bold">{{ \App\Support\Money::rub($best->amount) }}</span> <span class="text-sm text-ink-dim">{{ $best->user->shortName() }}</span>
            @elseif ($mine)<span class="nums text-lg font-bold text-accent-text">{{ \App\Support\Money::rub($mine->amount) }}</span>
            @elseif (!$staff)<span class="tag" style="--tag-bg:#fef3c7;--tag-text:#92400e;--tag-bg-d:#3f2606;--tag-text-d:#fcd34d">Без цены</span>@endif
        </x-slot:aside>
    </x-ui.peek>
</turbo-frame>
