{{-- Поля цен — одни на окошко строки (`admin/offers/price-steps`: черновик — блоком по шагам, остальное — разделом) и
     редактор (карточка «Цены», `form=offer-form`), 19.10.2026: «к унификации». Столбиком, каждое подписано и на своём месте:
     оценочная (только у вендора `floor_by_value`, Альфа; модератору — и если уже вписана), закупочная, цена
     продажи (админ) — рядом в две колонки; срок обязывающего — в «Показе» (модератору — тихой строкой под ценами) (только вендор по комиссии); заявленная, минимальная и доля
     свёрнуты строкой (раскрыты, если вписаны). Метки — не здесь: в окошке под блоком, в редакторе в шапке; «С НДС» — у заголовка «Цены» (`fields/vat-check`). Модератору —
     оценочная, закупочная, НДС и срок (OfferRequest::MODERATOR). Гаражной (`garageOnly`) — только закупочная.
     `now` — текущий шаг окошка (`floor` | `asking`): лаймовая рамка и фокус, поля шага — `data-save-own` (их несёт своя
     кнопка «Сохранить и дальше»); `plate` — поля на плашке (фон поверхности); `idp` — начало id полей. Серые подсказки
     заявленной и минимальной считаются на ходу (min-bid), закупочная от оценочной — value-floor. --}}
@php
    $form ??= null;
    $now ??= null;
    $idp ??= 'price-';
    $plate ??= false;
    $garageOnly ??= false;
    $admin = auth()->user()->canManageCrm();
    $byValue = \App\Offers\Pricing::byValueIds();
    $more = $offer->publish_price || $offer->min_bid_price || $offer->min_bid_share || $errors->hasAny(['publish_price', 'min_bid_price', 'min_bid_share']);
    $input = 'field-input nums'.($plate ? ' !bg-surface' : '');
    $ring = '!ring-2 !ring-accent';
    $bindingDays = $offer->vendor_id ? (int) \App\Vendors\Vendor::whereKey($offer->vendor_id)->value('binding_days') : 0;
@endphp
<div class="flex flex-col gap-2" data-controller="min-bid value-floor" data-value-floor-rules-value="@json(\App\Offers\Pricing::RULES)" @if ($offer->shown_floor) data-min-bid-shown-value="{{ $offer->shown_floor }}" @endif>
    <div class="flex flex-col gap-2">
        {{-- Оценочная — только у вендора, чья закупочная от неё (`floor_by_value`, Альфа); сменили вендора — прячется сама. --}}
        <div class="field" data-controller="by-vendor" data-by-vendor-ids-value="{{ json_encode($byValue) }}" @unless (in_array($offer->vendor_id, $byValue, true) || (! $admin && $offer->value)) hidden @endunless>
            <label for="{{ $idp }}value" class="field-label">Оценочная, ₽</label>
            <input type="text" id="{{ $idp }}value" name="value" @if ($form) form="{{ $form }}" @endif @if ($now) data-save-own @endif inputmode="decimal" autocomplete="off" enterkeyhint="next" class="{{ $input }}"
                data-controller="digits" data-action="input->digits#format input->value-floor#sync" data-value-floor-target="value" value="{{ old('value', $offer->value ? \App\Support\Money::nums($offer->value) : '') }}"
                @if ($now === 'floor' && in_array($offer->vendor_id, $byValue, true)) data-detail-focus @endif>
        </div>
        {{-- Закупочная и цена продажи — рядом (19.10.2026, владелец: «компактнее»). --}}
        <div class="grid grid-cols-2 gap-2">
            <div @class(['field', 'field-invalid' => $errors->has('floor_price'), 'col-span-2' => ! $admin || $garageOnly])>
                <label for="{{ $idp }}floor" class="field-label">Закупочная, ₽</label>
                <input type="text" id="{{ $idp }}floor" name="floor_price" @if ($form) form="{{ $form }}" @endif @if ($now) data-save-own @endif inputmode="decimal" autocomplete="off" enterkeyhint="{{ $admin ? 'next' : 'go' }}" class="{{ $input }} {{ $now === 'floor' ? $ring : '' }}"
                    data-controller="digits" data-action="input->digits#format" data-value-floor-target="floor" value="{{ old('floor_price', $offer->floor_price ? \App\Support\Money::nums($offer->floor_price) : '') }}"
                    @if ($now === 'floor' && ! in_array($offer->vendor_id, $byValue, true)) data-detail-focus @endif>
                @error('floor_price')<span class="field-error">{{ $message }}</span>@enderror
            </div>
            @if ($admin && ! $garageOnly)
                <div class="field @error('asking_price') field-invalid @enderror">
                    <label for="{{ $idp }}asking" class="field-label">Цена продажи, ₽</label>
                    <input type="text" id="{{ $idp }}asking" name="asking_price" @if ($form) form="{{ $form }}" @endif @if ($now) data-save-own @endif inputmode="decimal" autocomplete="off" enterkeyhint="go" class="{{ $input }} {{ $now === 'asking' ? $ring : '' }}"
                        data-controller="digits" data-action="input->digits#format" value="{{ old('asking_price', $offer->asking_price ? \App\Support\Money::nums($offer->asking_price) : '') }}"
                        @if ($now === 'asking') data-detail-focus @endif>
                    @error('asking_price')<span class="field-error">{{ $message }}</span>@enderror
                </div>
            @endif
        </div>
        {{-- Срок обязывающего живёт в «Показе» (`x-offer.showing`, 19.10.2026, владелец). У модератора «Показа» нет — ему
             тихой строкой под ценами, нажатие — календарь. Только вендор по комиссии (Совкомбанк, `Binding::applies`). --}}
        @if (! $admin && ! $garageOnly)
            @php $bindingHot = $offer->binding_until && \App\Offers\Binding::word($offer); @endphp
            <span class="flex flex-wrap items-baseline gap-x-1 text-sm {{ $bindingHot ? 'text-danger' : 'text-ink-muted' }}" data-controller="by-vendor" data-by-vendor-ids-value="{{ json_encode(\App\Offers\Binding::vendorIds()) }}" @unless (\App\Offers\Binding::applies($offer)) hidden @endunless>
                обязывающее до <x-ui.date name="binding_until" :id="$idp.'binding'" chip time default-time="23:59" :value="old('binding_until', $offer->binding_until?->format('Y-m-d\TH:i'))" :placeholder="$bindingDays ? 'по вендору '.$bindingDays.' дн' : 'указать'" :form="$form" class="nums underline decoration-dotted underline-offset-4"/>@if ($bindingHot)<span class="nums">, {{ \App\Offers\Binding::word($offer) }}</span>@endif
            </span>
            @error('binding_until')<span class="field-error">{{ $message }}</span>@enderror
        @endif
    </div>
    @if ($admin && ! $garageOnly)
        <details class="price-more" @if ($more) open @endif>
            <summary class="flex items-center gap-1 text-sm text-ink-muted">Заявленная, минимальная, доля <x-ui.icon name="chevron-down" class="price-more-chevron size-4"/></summary>
            <div class="mt-2 grid grid-cols-2 gap-2">
                <x-ui.field name="publish_price" label="Заявленная, ₽" :value="$offer->publish_price" :form="$form" :id="$idp.'publish'" span="col-span-2" :placeholder="$offer->bidFloor() ? \App\Support\Money::nums(\App\Offers\Offer::declaredFrom($offer->bidFloor())) : null"/>
                <x-ui.field name="min_bid_price" label="Минимальная, ₽" :value="$offer->min_bid_price" :form="$form" :id="$idp.'min'" :placeholder="$offer->minBid() ? \App\Support\Money::nums($offer->minBid()) : null"/>
                <x-ui.field name="min_bid_share" label="Доля до продажной" :value="$offer->min_bid_share" :form="$form" :id="$idp.'share'" placeholder="0,6"/>
            </div>
        </details>
    @endif
</div>
