{{-- Окошко строки таблицы машин закупки (фрейм peek) — здесь же оценка: лента фото,
     метки, наша цена справа, факты, поле «Наша цена» и «Дальше» (сохраняет и ведёт
     к следующей без цены; пустое поле — просто дальше), ниже цены Carcade,
     предложения менеджеров (выбрать/отменить прямо тут), характеристики, описание.
     Формы отвечают в окошко (PeekBack), строка таблицы — свежей из row. --}}
@php
    use App\Purchases\ImportState;
    $n = $purchase->number;
    $href = "/purchases/{$n}/{$car->ref}";
    $offers = $car->activeOfferList()->sortByDesc('amount')->values();
    $amber = '--tag-bg:#fef3c7;--tag-text:#92400e;--tag-bg-d:#3f2606;--tag-text-d:#fcd34d';
@endphp
<turbo-frame id="peek" target="_top">
    <x-ui.peek :href="$href" :title="$car->titleWithYear()" :photos="$car->visiblePhotos()" :facts="$car->facts()">
        <x-slot:marks>
            <span class="tag nums">{{ $car->dl }}</span>
            <span class="tag">{{ $car->kind->label() }}</span>
            @if ($car->settlement?->name ?? $car->city)<span class="tag">{{ $car->settlement?->name ?? $car->city }}</span>@endif
            @if ($car->vin)<span class="tag nums">{{ $car->vin }}</span>@endif
            @if ($car->fssp)<x-ui.pill tone="urgent">Ограничения ФССП</x-ui.pill>@endif
            @if ($car->specs_state->needsAttention())<span class="tag" style="{{ $amber }}">{{ $car->specs_state->label() }}</span>@elseif ($car->photos_state->needsAttention())<span class="tag" style="{{ $amber }}">{{ $car->photos_state->label() }}</span>@endif
            @if (in_array($car->photos_state, [ImportState::Pending, ImportState::Running], true))<span class="tag">фото едут</span>@endif
            @unless ($car->is_published)<span class="tag">скрыта</span>@endunless
        </x-slot:marks>
        <x-slot:aside>
            @if ($car->price_final)<span class="nums whitespace-nowrap text-[17px] font-bold">{{ \App\Support\Money::rub($car->price_final) }}</span>@endif
        </x-slot:aside>
        <x-slot:actions>
            <form method="post" action="{{ $href }}/estimate" class="flex w-full gap-2" data-controller="bid" data-bid-asking-value="0">
                @csrf
                <input type="hidden" name="price_final" data-bid-target="amount" value="{{ $car->price_final }}">
                <input type="text" inputmode="numeric" autocomplete="off" enterkeyhint="go" class="field-input nums min-w-0 flex-1" placeholder="Наша цена, ₽" aria-label="Наша цена, ₽"
                    data-bid-target="display" data-action="input->bid#input" value="{{ $car->price_final ? \App\Support\Money::nums($car->price_final) : '' }}" data-peek-focus>
                <button type="submit" class="btn btn-accent shrink-0 px-5">Дальше</button>
            </form>
            @error('price_final')<p class="w-full text-sm text-danger">{{ $message }}</p>@enderror
        </x-slot:actions>
        <dl class="mt-4 grid grid-cols-[1fr_auto] gap-x-4 gap-y-1.5">
            @foreach (['С учётом переоценки' => $car->price_revalued, 'Для размещения' => $car->price_listing] as $label => $value)
                <dt class="text-sm text-ink-dim">{{ $label }}</dt>
                @if ($value)<dd class="nums text-right font-medium">{{ \App\Support\Money::rub($value) }}</dd>@else<dd class="nums text-right text-ink-dim">—</dd>@endif
            @endforeach
        </dl>
        @if ($offers->isNotEmpty())
            <div class="mt-3 flex flex-wrap items-center gap-1.5">
                @foreach ($offers as $offer)<x-purchase.offer-chip :offer="$offer" :car="$car"/>@endforeach
            </div>
        @else
            <p class="mt-3 text-sm text-ink-dim">Менеджеры цены не предложили</p>
        @endif
        <dl class="mt-4 grid grid-cols-2 gap-x-6 gap-y-2.5">
            @foreach ($car->factsList() as $label => $value)
                <div class="min-w-0"><dt class="text-xs text-ink-dim">{{ $label }}</dt><dd class="nums break-words text-sm font-medium">{{ $value }}</dd></div>
            @endforeach
        </dl>
        @if ($car->description)<p class="mt-4 whitespace-pre-line text-sm text-ink-muted">{{ $car->description }}</p>@endif
        <x-slot:row><x-purchase.table-row :car="$car" :purchase="$purchase"/></x-slot:row>
    </x-ui.peek>
</turbo-frame>
