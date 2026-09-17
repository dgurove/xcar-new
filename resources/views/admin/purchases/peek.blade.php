{{-- Окошко строки таблицы машин закупки (фрейм peek) — здесь же оценка: лента фото,
     метки, наша цена справа, факты, поле «Наша цена» и «Дальше» (сохраняет и ведёт
     к следующей без цены; пустое поле — просто дальше), ниже предложения менеджеров
     (выбрать/отменить прямо тут), описание. Цены Carcade — подписями под полем,
     характеристики — метками и фактами наверху, отдельных блоков нет: окошко — полэкрана.
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
            @if ($car->settlement?->name ?? $car->city)<x-ui.place class="tag">{{ $car->settlement?->name ?? $car->city }}</x-ui.place>@endif
            <x-ui.vin-code :vin="$car->vin" class="tag"/>
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
                <input type="text" inputmode="numeric" autocomplete="off" enterkeyhint="go" class="field-input field-s nums min-w-0 flex-1" placeholder="Наша цена, ₽" aria-label="Наша цена, ₽"
                    data-bid-target="display" data-action="input->bid#input" value="{{ $car->price_final ? \App\Support\Money::nums($car->price_final) : '' }}" data-peek-focus>
                <button type="submit" class="btn btn-s btn-accent shrink-0">Дальше</button>
            </form>
            @error('price_final')<p class="w-full text-sm text-danger">{{ $message }}</p>@enderror
            {{-- Цены Carcade — под полем, подписями: это ориентир для нашей цены, а не характеристика ТС. --}}
            @if ($car->price_revalued || $car->price_listing)
                <dl class="flex w-full gap-6">
                    @foreach (['С учётом переоценки' => $car->price_revalued, 'Для размещения' => $car->price_listing] as $label => $value)
                        @if ($value)<div><dt class="text-xs text-ink-dim">{{ $label }}</dt><dd class="nums text-sm font-medium">{{ \App\Support\Money::rub($value) }}</dd></div>@endif
                    @endforeach
                </dl>
            @endif
        </x-slot:actions>
        @if ($offers->isNotEmpty())
            <div class="mt-3 flex flex-wrap items-center gap-1.5">
                @foreach ($offers as $offer)<x-purchase.offer-chip :offer="$offer" :car="$car"/>@endforeach
            </div>
        @else
            <p class="mt-3 text-sm text-ink-dim">Менеджеры цены не предложили</p>
        @endif
        @if ($car->description)<p class="mt-4 whitespace-pre-line text-sm text-ink-muted">{{ $car->description }}</p>@endif
        <x-slot:row><x-purchase.table-row :car="$car" :purchase="$purchase"/></x-slot:row>
    </x-ui.peek>
</turbo-frame>
