{{-- Строка черновика у поставщика (08.11.2026, владелец: «таблица выглядит отвратительно»): главный кадр, название с годом,
     № убытка, город, оценочная полем прямо в строке с закупочной на лету и дата заведения. Enter — цена сохраняется
     (`price_row`), фокус — на поле следующей строки; у вписанной — «Отправить»: только по ней строка уходит к нам (владелец:
     «автоматически не отправлять»). Нажатие по строке мимо поля — редактор. Без марки и модели
     цену не отдают — вместо поля «Заполнить». Ничего нашего: ни авторов, ни источников, ни состояний.
     `data-detail-key` — по нему `Support\Table\Widths` находит строки и считает ширины столбцов. --}}
@php
    $href = '/offers/'.$offer->id;
    $cover = $covers[$offer->car_id] ?? null;
    $priced = $offer->brand_id && $offer->model_id;
    // Вписал, но не отправил (`partner_price_at`) — его оценочная в поле, закупочная рядом, «Отправить» видна.
    $waiting = $offer->partner_price_at !== null && $offer->floor_price > 0;
@endphp
<tr id="partner-offer-{{ $offer->id }}" @class(['is-priced' => $waiting]) data-detail-key="{{ $offer->id }}" data-search-row data-controller="price-row"
    data-price-row-url-value="{{ $href }}" data-price-row-rules-value="@json(\App\Offers\Pricing::rulesFor($offer))" data-action="click->price-row#open">
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('photo')
    <td class="{{ $col->classes() }}">
        <a href="{{ $href }}" class="partner-thumb" tabindex="-1" aria-hidden="true">
            @if ($cover)
                <img src="/photos/{{ $cover->id }}/w320?v={{ $cover->updated_at?->timestamp }}" alt="" decoding="async">
            @else
                <x-ui.cat-icon :category="$offer->category()"/>
            @endif
        </a>
    </td>
        @break
        @case('title')
    <td class="{{ $col->classes() }}">
        <a href="{{ $href }}" class="row-link"><span class="cell-title"><span class="cell-name">{{ $offer->brand_id ? $offer->titleWithYear() : 'Без марки' }}</span></span></a>
        <span class="cell-sub">
            <span class="nums sm:hidden">{{ $offer->claim_ref }}</span>
            @if ($offer->settlement)<x-ui.place class="sm:hidden">{{ $offer->settlement->title() }}</x-ui.place>@endif
        </span>
    </td>
        @break
        @case('ref')
    <td class="{{ $col->classes() }}"><x-ui.copy-code :value="$offer->claim_ref"/></td>
        @break
        @case('city')
    <td class="{{ $col->classes() }}">@if ($offer->settlement)<x-ui.place>{{ $offer->settlement->title() }}</x-ui.place>@endif</td>
        @break
        @case('value')
    <td class="{{ $col->classes() }}">
        @if ($priced)
            <div class="partner-price flex">
                <input type="text" inputmode="decimal" enterkeyhint="done" autocomplete="off" placeholder="Вписать" aria-label="Оценочная, ₽" value="{{ $waiting && $offer->value ? \App\Support\Money::nums((int) $offer->value) : '' }}"
                    class="partner-price-input nums" data-controller="digits" data-price-row-target="input"
                    data-action="input->digits#format input->price-row#sync keydown->price-row#key">
            </div>
        @else
            <a href="{{ $href }}" class="partner-price-fill">Заполнить <x-ui.icon name="chevron-right" class="size-4"/></a>
        @endif
    </td>
        @break
        @case('floor')
    <td class="{{ $col->classes() }}"><span class="partner-price-floor nums" data-price-row-target="floor">{{ $waiting ? \App\Support\Money::rub($offer->floor_price) : '' }}</span></td>
        @break
        @case('send')
    <td class="{{ $col->classes() }}">@if ($priced)<button type="button" class="partner-send" data-price-row-target="send" data-action="price-row#send">Отправить</button>@endif</td>
        @break
        @case('created')
    <td class="{{ $col->classes() }}">{{ \App\Support\Ago::html($offer->created_at) }}</td>
        @break
        @endswitch
    @endforeach
</tr>
