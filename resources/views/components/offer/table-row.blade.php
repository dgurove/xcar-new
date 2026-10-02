{{-- Строка таблицы предложений (CRM), по образцу «Наличия» парковки. Ячейка в два этажа: иконка типа ТС и название с
     «рекомендуем», под ним логотип страховой с номером убытка, номер предложения, приём (таймер или состояние, цветом
     по тону) и подтверждения; от 640 они встают своими столбцами (вендор с номером убытка — одним). Справа цена
     продажи (у черновика без неё — лаймовое «оценить»), последним столбцом закупочная; на телефоне она под ценой.
     У черновика нет ни номера (он ещё не выставлен), ни слова «черновик»: его и так видно по «оценить» и цене.
     В галерее вместо подтверждений — интерес. Нажатие — окошко; data-unpriced — черновик без цены продажи,
     по ним окошко идёт «Дальше» («Оценить»). --}}
@props(['offer', 'gallery' => false])
@php
    use App\Offers\OfferState;
    $n = $offer->number;
    $price = \App\Offers\PriceView::for($offer, auth()->user());
    $left = $gallery ? null : $offer->secondsLeft();
    $tone = match ($offer->state->tone()) { 'open' => 'text-accent-text', 'urgent' => 'text-urgent', 'danger' => 'text-danger', default => '' };
    $count = $gallery ? (int) $offer->interests_count : (int) $offer->active_bids_count;
    $countWord = $gallery ? 'интерес '.$count : $count.' подтв.';
    $timer = $left !== null && $left > 0;
    // Приём закрылся, а подтверждения есть — ход наш: «выбрать» оранжевым; без них — «приём закрыт».
    $pick = $offer->state === OfferState::Open && ! $timer && $offer->bids_close_at;
    $stateWord = $offer->state === OfferState::Open ? ($pick ? ($count ? 'выбрать' : 'приём закрыт') : 'приём') : mb_strtolower($offer->state->label());
    // Логотип — из одной выборки вендоров на страницу, а не связью на каждую строку.
    $vendor = $offer->vendor_id ? \App\Vendors\Vendor::badges()->get($offer->vendor_id) : null;
    $offer->loadMissing('parkVehicle:id,offer_id,category');
    $draft = $offer->state === OfferState::Draft;
    $unpriced = $draft && ! $offer->asking_price;
    // «Оценить» — дело админа: модератор цену продажи не ставит, у него на месте слова пусто, закупочная — как была.
    $rate = $unpriced && auth()->user()?->canManageCrm();
@endphp
<tr id="{{ ($gallery ? 'gallery-' : 'admin-offer-') }}{{ $n }}" data-offer-number="{{ $n }}" data-row-key="offer-{{ $offer->id }}" data-peek-url="/offers/{{ $n }}/peek{{ $gallery ? '?gallery=1' : '' }}" data-href="/offers/{{ $n }}" tabindex="0" @if ($rate) data-unpriced @endif>
    <td class="grow">
        <span class="cell-title"><x-ui.cat-icon :category="$offer->category()"/>{{ $offer->titleWithYear() }}@if ($offer->recommended)<x-offer.recommended/>@endif</span>
        <span class="cell-sub" data-controller="fitline">
            {{-- Номера — одним неразрывным куском: не влезают — строка ужимается (fitline), а не переносится. --}}
            <span class="fit-core sm:hidden"><x-vendor.ref :vendor="$vendor" :ref="$offer->claim_ref"/>@unless ($draft)<span>№ {{ $n }}</span>@endunless</span>
            @if ($timer)<span class="nums sm:hidden {{ $offer->isEndingSoon() ? 'text-urgent' : 'text-accent-text' }}" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="приём закрыт"></span>
            @elseif (! $draft)<span class="sm:hidden {{ $tone }}">{{ $stateWord }}</span>@endif
            @if ($count)<span class="sm:hidden {{ $gallery ? 'text-accent-text' : 'text-urgent' }}">{{ $countWord }}</span>@endif
        </span>
    </td>
    {{-- Вендор и номер убытка одним столбцом, как в «Наличии»: логотип (имя — подсказкой), номер с копированием. --}}
    <td class="hidden sm:table-cell"><span class="vendor-ref">@if ($vendor)<button type="button" class="vendor-tip" data-tip="{{ $vendor->name }}" aria-label="{{ $vendor->name }}"><x-vendor.logo :vendor="$vendor"/></button>@endif @if ($offer->claim_ref)<x-ui.copy-code :value="$offer->claim_ref"/>@endif</span></td>
    {{-- У черновика номера ещё нет — на его месте тот, кто завёл: девчонки заводят пачками, админ видит чьё. --}}
    <td class="cell-dim nums hidden sm:table-cell">@if (! $draft){{ $n }}@elseif ($offer->moderator)<x-ui.avatar :user="$offer->moderator" :size="22" title="{{ $offer->moderator->name }}"/>@endif</td>
    <td class="hidden sm:table-cell">
        @if ($timer)<span class="nums {{ $offer->isEndingSoon() ? 'text-urgent' : 'text-accent-text' }}" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="Приём закрыт"></span>
        @elseif ($pick)<span class="{{ $count ? 'text-urgent' : 'text-ink-muted' }}">{{ $count ? 'Выбрать' : 'Приём закрыт' }}</span>
        @else<span class="{{ $tone }}">{{ $offer->state === OfferState::Open ? 'Приём' : $offer->state->label() }}</span>@endif
    </td>
    <td class="num nums hidden sm:table-cell {{ $gallery ? 'text-accent-text' : 'text-urgent' }}">{{ $count ?: '' }}@if (! $gallery && $offer->top_bid)<span class="ml-1 text-sm text-ink-muted">до {{ \App\Support\Money::nums($offer->top_bid) }}</span>@endif</td>
    {{-- Цена продажи (у черновика без неё — «оценить»), справа закупочная; на телефоне закупочная — под ценой. --}}
    <td class="num nums">
        @if ($rate)<span class="text-accent-text">оценить</span>
        @elseif ($unpriced)
        @elseif ($price->shown()){{ $price::money($price->to) }}
        @elseif ($gallery)<span class="text-accent-text">Скоро</span>@endif
        @if ($offer->floor_price)<span class="cell-sub sm:hidden">{{ \App\Support\Money::nums($offer->floor_price) }}</span>@endif
    </td>
    <td class="cell-dim num nums col-peek-hide hidden sm:table-cell">{{ $offer->floor_price ? \App\Support\Money::nums($offer->floor_price) : '' }}</td>
</tr>
