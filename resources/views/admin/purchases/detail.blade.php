{{-- Карточка строки таблицы машин закупки (фрейм detail) — здесь же оценка: лента фото,
     метки, наша цена справа, факты, поле «Наша цена» и «Дальше» (сохраняет и ведёт
     к следующей без цены; пустое поле — просто дальше), ниже предложения менеджеров
     (выбрать/отменить прямо тут), описание. Цены Carcade — подписями под полем,
     характеристики — метками и фактами наверху, отдельных блоков нет: карточка — полэкрана.
     Формы отвечают в карточку (DetailBack), строка таблицы — свежей из row. ТС, ушедшая в предложение по
     контрпредложению, вместо оценки показывает ссылку на предложение; цены менеджеров остаются. --}}
@php
    use App\Purchases\ImportState;
    $n = $purchase->number;
    $href = "/purchases/{$n}/{$car->ref}";
    $offers = $car->activeOfferList()->sortByDesc('amount')->values();
@endphp
<x-ui.detail>
    <x-ui.row-card :href="$href" :title="$car->titleWithYear()" :photos="$car->visiblePhotos()" :facts="$car->facts()">
        {{-- Коды чипами, что не так — словами, где и какой тип — фразой (владелец 10.10.2026: «одно к машине относится,
             другое к фактам»). --}}
        <x-slot:marks>
            <span class="tag nums gap-1">ДЛ<x-ui.copy-code :value="$car->dl"/></span>
            <x-ui.vin-code :vin="$car->vin" class="tag"/>
            @if ($car->fssp)<x-ui.state tone="urgent">Ограничения ФССП</x-ui.state>@endif
            @if ($car->specs_state->needsAttention())<x-ui.state tone="urgent">{{ $car->specs_state->label() }}</x-ui.state>@elseif ($car->photos_state->needsAttention())<x-ui.state tone="urgent">{{ $car->photos_state->label() }}</x-ui.state>@endif
            @if (in_array($car->photos_state, [ImportState::Pending, ImportState::Running], true))<x-ui.state>фото едут</x-ui.state>@endif
            @unless ($car->is_published)<x-ui.state>скрыта</x-ui.state>@endunless
        </x-slot:marks>
        <x-slot:line>
            @if ($car->settlement?->title() ?? $car->city)<x-ui.place>{{ $car->settlement?->title() ?? $car->city }}</x-ui.place>@endif
            <span>{{ mb_strtolower($car->kind->label()) }}</span>
        </x-slot:line>
        <x-slot:aside>
            @if ($car->price_final)<span class="nums whitespace-nowrap text-lg font-bold">{{ \App\Support\Money::rub($car->price_final) }}</span>@endif
        </x-slot:aside>
        <x-slot:actions>
            @if ($car->offer)
                <x-ui.pill tone="open" href="/offers/{{ $car->offer->number }}">Предложение № {{ $car->offer->number }}</x-ui.pill>
            @else
                <form method="post" action="{{ $href }}/estimate" class="flex w-full gap-2" data-controller="bid" data-bid-asking-value="0">
                    @csrf
                    <input type="hidden" name="price_final" data-bid-target="amount" value="{{ $car->price_final }}">
                    <input type="text" inputmode="decimal" autocomplete="off" enterkeyhint="go" class="field-input field-s nums min-w-0 flex-1" placeholder="Наша цена, ₽" aria-label="Наша цена, ₽"
                        data-bid-target="display" data-action="input->bid#input" value="{{ $car->price_final ? \App\Support\Money::nums($car->price_final) : '' }}" data-detail-focus>
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
    </x-ui.row-card>
</x-ui.detail>
