{{-- Строка таблицы предложений (CRM), по образцу «Наличия» парковки. Ячейка в два этажа: иконка типа ТС и название с
     «рекомендуем», под ним логотип страховой с номером убытка, номер предложения, приём (таймер или состояние, цветом
     по тону) и подтверждения; от 640 они встают своими столбцами (вендор с номером убытка — одним). Справа цена
     продажи (у черновика без неё — чип «Оценить»), последним столбцом закупочная; на телефоне она под ценой.
     У черновика нет ни номера (он ещё не выставлен), ни слова «черновик»: его и так видно по чипу «Оценить» и цене.
     Черновик из парковки — исключение: на месте состояния «парковка с …» (дата приёма ТС). Последний столбец —
     когда заведено.
     В галерее вместо подтверждений — интерес. Нажатие — карточка; data-unpriced — черновик без цены продажи,
     по ним карточка идёт «Дальше» («Оценить»). checkable — галочка первым столбцом («Оцененные», «Публикация»; group — ключ
     слота для галочки группы); не готовое к продаже вместо галочки получает красное «нет фото» второй строкой, срок
     страховой раньше ближайшего слота — тоже красным. cols — столбцы вкладки (OfferController::columns, null — все):
     без «Состояния» «парковка с …» уходит второй строкой под название. --}}
@props(['offer', 'gallery' => false, 'checkable' => false, 'group' => null, 'cols' => null])
@php
    use App\Offers\OfferState;
    $n = $offer->number;
    $price = \App\Offers\PriceView::for($offer, auth()->user());
    // Подтверждения принимает только админ: модератору ни их числа, ни отсчёта приёма, ни «выбрать» — «в продаже».
    $admin = (bool) auth()->user()?->canManageCrm();
    $left = $gallery || ! $admin ? null : $offer->secondsLeft();
    $tone = match ($offer->state->tone()) { 'open' => 'text-accent-text', 'urgent' => 'text-urgent', 'danger' => 'text-danger', default => '' };
    $count = ! $admin ? 0 : ($gallery ? (int) $offer->interests_count : (int) $offer->active_bids_count);
    // Гаражные подтверждения без суммы — отдельно словом (владелец 05.10.2026: «непонятно, что такое 1»).
    $garageBids = $gallery ? 0 : min($count, (int) $offer->garage_bids_count);
    $priceBids = $count - $garageBids;
    $countWord = $gallery ? 'интерес '.$count : implode(', ', array_filter([$priceBids ? $priceBids.' подтв.' : null, $garageBids ? $garageBids.' в гараж' : null]));
    $timer = $left !== null && $left > 0;
    // Приём закрылся, а подтверждения есть — ход наш: «выбрать» оранжевым; без них — «приём закрыт».
    $pick = $admin && $offer->state === OfferState::Open && ! $timer && $offer->bids_close_at;
    $stateWord = $offer->state === OfferState::Open ? ($admin ? ($pick ? ($count ? 'выбрать' : 'приём закрыт') : 'приём') : 'в продаже') : mb_strtolower($offer->state->label());
    // Логотип — из одной выборки вендоров на страницу, а не связью на каждую строку.
    $vendor = $offer->vendor_id ? \App\Vendors\Vendor::badges()->get($offer->vendor_id) : null;
    $offer->loadMissing('parkVehicle:id,offer_id,category,accepted_at,created_at');
    $draft = $offer->state === OfferState::Draft;
    $unpriced = $draft && ! $offer->asking_price;
    // «Оценить» — дело админа: модератор цену продажи не ставит, у него на месте слова пусто, закупочная — как была.
    $rate = $unpriced && auth()->user()?->canManageCrm();
    // Закупочной нет — «Заполнить» в её ячейке (вкладка «Без закупочной цены», дело модератора); там же столбца цены нет,
    // и на телефоне справа стоит закупочная.
    $nofloor = $unpriced && ! $offer->floor_price && ! $offer->isScheduled();
    $floorSide = $cols !== null && ! in_array('price', $cols, true);
    // data-unpriced — у человека по строке есть дело (OfferController::todo): по таким карточка идёт «Дальше».
    $todo = auth()->user() && \App\Http\Admin\OfferController::todo($offer, auth()->user());
    // Поставлено в слот — «выйдет сегодня в 16:00» на месте состояния.
    $slot = $offer->isScheduled() ? 'выйдет '.\App\Offers\Slots::phrase($offer->slot_at) : null;
    // Чего не хватает для продажи — у оценённого черновика: галочки у такого нет.
    $missing = $checkable && $draft && ! $slot ? array_map(fn ($m) => 'нет '.match ($m) { 'фотографии' => 'фото', 'марка' => 'марки', 'цена продажи' => 'цены', default => $m }, \App\Offers\Actions\ChangeOfferState::missing($offer)) : [];
    $has = fn (string $k) => $cols === null || in_array($k, $cols, true);
    $when = $cols !== null && $has('published') ? $offer->published_at : ($has('created') ? $offer->created_at : null);
    // VIN на Мигторге не дают — его запрашивают у страховой (05.10.2026): красным у названия, пока продажа жива.
    $noVin = blank($offer->vin) && in_array($offer->state, [OfferState::Draft, OfferState::Gallery, OfferState::Open], true);
    $late = $checkable && $draft && $offer->insurer_deadline_at && $offer->insurer_deadline_at->copy()->endOfDay()->lt($offer->slot_at ?? \App\Offers\Slots::nearest());
@endphp
<tr data-detail-key="{{ $n }}" data-search-row id="{{ ($gallery ? 'gallery-' : 'admin-offer-') }}{{ $n }}" data-offer-number="{{ $n }}" @if ($todo) data-unpriced @endif>
    @if ($checkable)<td class="pick-cell">@unless ($missing)<label class="row-check" aria-label="Выбрать"><span class="check"><input type="checkbox" id="pick-{{ $n }}" data-turbo-permanent name="offers[]" value="{{ $n }}" form="offers-pick" data-pick-target="box" data-group="{{ $group }}" data-action="pick#sync"></span></label>@endunless</td>@endif
    <td class="grow">
        <x-ui.row-link :key="$n"><span class="cell-title"><x-ui.cat-icon :category="$offer->category()"/><span class="cell-name">{{ $offer->titleWithYear() }}</span>@if ($offer->recommended)<x-offer.recommended/>@endif<x-ui.links :offer="$offer"/>@if ($noVin)<span class="ml-1.5 text-sm text-danger">Нет VIN</span>@endif</span></x-ui.row-link>
        {{-- Телефон: строка переносится, а не режется «…» (владелец 04.10.2026: «поля не должны исчезать»); город, даты и
             прочие столбцы — в «Подробной таблице». --}}
        <span class="cell-sub cell-sub--wrap" data-controller="fitline">
            {{-- Номера — одним неразрывным куском: не влезают — строка ужимается (fitline), а не переносится. --}}
            <span class="fit-core sm:hidden"><x-vendor.ref :vendor="$vendor" :ref="$offer->claim_ref"/></span>
            @if ($timer)<span class="nums sm:hidden {{ $offer->isEndingSoon() ? 'text-urgent' : 'text-accent-text' }}" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="приём закрыт" data-timer-coarse-value="true" data-timer-word-value="">{{ \App\Support\Ago::left($offer->bids_close_at, '') }}</span>
            @elseif ($slot && $has('state'))<span class="sm:hidden text-accent-text">{{ $slot }}</span>
            {{-- Телефон, «Опубликованные»: у ждущих решения важнее число подтверждений, дата закрытия — у остальных. --}}
            @elseif ($pick && $cols !== null && ! $count)<span class="sm:hidden nums text-ink-muted">закрыт {{ $offer->bids_close_at->translatedFormat('j M, H:i') }}</span>
            @elseif (! $draft && ! ($pick && $cols !== null))<span class="sm:hidden {{ $tone }}">{{ $stateWord }}</span>@endif
            @if ($missing)<span class="text-danger">{{ implode(', ', $missing) }}</span>@endif
            @if ($late)<span class="text-danger nums">страховая до {{ $offer->insurer_deadline_at->translatedFormat('j M') }}</span>@endif
        </span>
    </td>
    {{-- Вендор и номер убытка одним столбцом, как в «Наличии»: логотип (имя — подсказкой), номер с копированием. --}}
    @if ($has('vendor'))<td class="hidden sm:table-cell"><span class="vendor-ref">@if ($vendor)<button type="button" class="vendor-tip" data-tip="{{ $vendor->name }}" aria-label="{{ $vendor->name }}"><x-vendor.logo :vendor="$vendor"/></button>@endif @if ($offer->claim_ref)<x-ui.copy-code :value="$offer->claim_ref"/>@endif</span></td>@endif
    {{-- Город — именем без номера региона: столбец узкий, регион виден в карточке. --}}
    @if ($has('city'))<td class="col-detail-hide hidden sm:table-cell">@if ($offer->settlement)<x-ui.place class="whitespace-nowrap text-ink-muted">{{ $offer->settlement->name }}</x-ui.place>@endif</td>@endif
    @if (! $has('state'))<td class="hidden sm:table-cell"></td>
    @else
    <td class="hidden sm:table-cell">
        @if ($timer)<span class="nums {{ $offer->isEndingSoon() ? 'text-urgent' : 'text-accent-text' }}" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="Приём закрыт" data-timer-coarse-value="true">{{ \App\Support\Ago::left($offer->bids_close_at) }}</span>
        @elseif ($slot)<span class="text-accent-text">{{ $slot }}</span>
        {{-- Во вкладке «Опубликованные» «выбрать» говорит заголовок группы — в строке когда закрылся приём. --}}
        @elseif ($pick && $cols !== null)<span class="nums {{ $count ? 'text-urgent' : 'text-ink-muted' }}">закрыт {{ $offer->bids_close_at->translatedFormat('j M, H:i') }}</span>
        @elseif ($pick)<span class="{{ $count ? 'text-urgent' : 'text-ink-muted' }}">{{ $count ? 'Выбрать' : 'Приём закрыт' }}</span>
        @else<span class="{{ $tone }}{{ $draft ? ' text-ink-dim' : '' }}">{{ $offer->state === OfferState::Open ? ($admin ? 'Приём' : 'В продаже') : $offer->state->label() }}</span>@endif
    </td>
    @endif
    {{-- «2 до 5 440 000, 1 в гараж». --}}
    @if ($admin && $has('bids'))<td class="num nums hidden sm:table-cell {{ $gallery ? 'text-accent-text' : 'text-urgent' }}">@if ($gallery){{ $count ?: '' }}@else{{ $priceBids ?: '' }}@if ($priceBids && $offer->top_bid)<span class="ml-1 text-sm text-ink-muted">до {{ \App\Support\Money::nums($offer->top_bid) }}</span>@endif{{ $priceBids && $garageBids ? ',' : '' }}@if ($garageBids)<span class="ml-1 whitespace-nowrap">{{ $garageBids }} <span class="text-sm">в гараж</span></span>@endif @endif</td>@endif
    {{-- Закупочная, справа от неё цена продажи (у черновика без неё — чип «Оценить»); на телефоне столбца закупочной нет —
         она под ценой. --}}
    @if ($cols !== null && $has('value'))<td class="cell-dim num nums col-detail-hide hidden sm:table-cell">{{ $offer->value && \App\Vendors\Vendor::ratesByValue($offer->vendor_id) ? \App\Support\Money::nums($offer->value) : '' }}</td>@endif
    @if ($has('floor') && $floorSide)<td class="num nums">@if ($nofloor)<x-offer.rate-chip :offer="$offer" label="Заполнить"/>@elseif ($offer->floor_price){{ \App\Support\Money::nums($offer->floor_price) }}@endif</td>
    @elseif ($has('floor'))<td class="cell-dim num nums col-detail-hide hidden sm:table-cell">{{ $offer->floor_price ? \App\Support\Money::nums($offer->floor_price) : '' }}</td>@endif
    @if ($has('price'))<td class="num nums">
        @if ($rate)<x-offer.rate-chip :offer="$offer"/>
        @elseif ($unpriced)
        @elseif ($price->shown()){{ $price::money($price->to) }}
        @elseif ($gallery)<span class="text-accent-text">Скоро</span>@endif
        {{-- Телефон: подтверждения — под ценой, а не в конце второй строки: там их съедало «…» за номерами (владелец
             04.10.2026: «самое важное, никогда нельзя скрывать»). Есть подтверждения — они вместо закупочной. --}}
        @if ($count)<span class="cell-sub sm:hidden nums {{ $gallery ? '!text-accent-text' : '!text-urgent' }}">{{ $countWord }}</span>
        @elseif ($offer->floor_price && $has('floor'))<span class="cell-sub sm:hidden">{{ \App\Support\Money::nums($offer->floor_price) }}</span>@endif
    </td>@endif
    {{-- Когда заведено (черновик — когда начали): последним, приглушённо и в одну строку; в карточке справа столбца нет. --}}
    @if ($has('created') || ($cols !== null && $has('published')))<td class="num nums col-detail-hide hidden whitespace-nowrap !text-xs !text-ink-dim sm:table-cell">
        {{-- Без столбца № кто завёл черновик — аватаром перед датой. --}}
        @if ($draft && $offer->moderator)<span class="inline-flex items-center gap-2 align-middle"><x-ui.avatar :user="$offer->moderator" :size="20" title="{{ $offer->moderator->name }}"/>@endif{{ $when?->translatedFormat($when->isCurrentYear() ? 'j M, H:i' : 'j M Y, H:i') }}@if ($draft && $offer->moderator)</span>@endif
    </td>@endif
</tr>
