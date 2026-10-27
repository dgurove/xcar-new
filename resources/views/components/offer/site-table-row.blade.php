{{-- Строка таблицы предложений на сайте (менеджер, покупатель, избранное). Ячейка в два этажа: название с
     «рекомендуем», под ним номер, приём (срок с иконкой секундомера, 07.10.2026: «осталось» и голое «12 д 5 ч» владельцу не нравились)
     и город; от 640 номер, приём и город встают
     столбцами. Справа цена так, как видит человек, или «Узнать», под ней заявленная, на телефоне без «сколько прошло» (07.10.2026, владелец: «зачем писать 2 дн»), на ПК — столбцом. Нажатие — карточка.
     Столбцы — `CatalogController::table` (шапка и заголовки пачек — по нему же). --}}
@props(['offer', 'table', 'context' => null, 'estimate' => null, 'only' => false])
@php
    $user = auth()->user();
    $n = $offer->number;
    $gallery = $offer->isGallery();
    $price = \App\Offers\PriceView::for($offer, $user);
    $bids = $user?->bidsOnSite() ?? false;
    $left = $gallery || !$bids ? null : $offer->secondsLeft();
    // Новое и горящее — точкой перед названием, как непрочитанное (владелец 10.10.2026); горящее важнее.
    // Отдано на оценку (11.10.2026): ход менеджера — оранжевой точкой; у черновика номер временный, его не пишем.
    // Открыто только ему (пачка «Открыты только Вам», 11.10.2026) — красной точкой, важнее нового и горящего.
    // Срочное (12.10.2026) — огненной точкой, важнее всего, кроме оценки.
    $tone = match (true) {
        $estimate !== null => $estimate->isGiven() ? null : 'hot',
        $offer->isUrgent() => 'urgent',
        $only => 'only',
        $gallery => null,
        $bids && $offer->isEndingSoon() => 'hot',
        $offer->isFresh() => 'fresh',
        default => null,
    };
    $myBid = $bids && !$gallery && $offer->relationLoaded('bids') ? $offer->bids->firstWhere('user_id', $user->id) : null;
    // Галерея: «Проявить интерес» прямо в строке (владелец 11.10.2026: в строчном виде кнопки не хватало), как у плитки.
    $myInterest = $gallery && $user ? ($offer->relationLoaded('interests') ? $offer->interests->firstWhere('user_id', $user->id) : $offer->interests()->where('user_id', $user->id)->first()) : null;
    $href = $context?->offerUrl($offer) ?? '/offers/'.$n;
    // Сколько прошло — с тех пор, как открылось этому человеку (волна, показ), как и точка «новое».
    $since = $offer->openedAt() ?? $offer->created_at;
    $city = $offer->settlement?->title();
@endphp
<tr data-detail-key="{{ $n }}" data-search-row id="offer-{{ $n }}" data-offer-number="{{ $n }}" @if ($user) data-viewed-key="{{ $n }}" @endif @if ($offer->viewed) data-viewed @endif>
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('title')
    <td class="{{ $col->classes() }}">
        <x-ui.row-link :key="$n"><span class="cell-title">@if ($tone && $tone !== 'urgent')<span class="card-tone card-tone--{{ $tone }}" aria-label="{{ $estimate ? 'Нужно оценить' : match ($tone) { 'only' => 'Только вам', 'hot' => 'Горящее', 'urgent' => 'Срочно', default => 'Новое' } }}"></span>@endif{{ $offer->titleWithYear() }}@if ($offer->recommended)<x-offer.recommended/>@endif{{ "" }}@if ($offer->isUrgent())<x-offer.urgent/>@endif</span></x-ui.row-link>
        <span class="cell-sub">
            @unless ($estimate)<span class="sm:hidden">№ {{ $n }}</span>@endunless
            @if ($estimate)@unless ($estimate->isGiven())<span class="cell-timer sm:hidden"><x-ui.icon name="timer" class="size-3.5"/><x-offer.estimate-left :estimate="$estimate" word=""/></span>@endunless
            @elseif ($gallery)<x-offer.gallery-left :offer="$offer" word="" class="sm:hidden"/>
            @elseif ($left !== null && $left > 0)<span class="cell-timer sm:hidden {{ $offer->isEndingSoon() ? 'text-urgent' : 'text-ink-muted' }}"><x-ui.icon name="timer" class="size-3.5"/><span class="nums" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="приём закрыт" data-timer-coarse-value="true" data-timer-word-value="">{{ \App\Support\Ago::left($offer->bids_close_at, '') }}</span></span>
            @elseif ($offer->state === \App\Offers\OfferState::Sold && $offer->seesDeal($user))<span class="sm:hidden text-urgent">идёт сделка</span>
            @elseif ($bids && !$offer->bidsOpen())<span class="sm:hidden">приём закрыт</span>@endif
            @if ($city)<span class="sm:hidden">{{ $city }}</span>@endif
            {{-- Открывал — «просмотрено» (`ReadOffer`); показывает `data-viewed` строки, его же ставит клик (`app.js`). --}}
            @if ($user)<span class="row-viewed">просмотрено</span>@endif
        </span>
    </td>
        @break
        @case('number')
    <td class="{{ $col->classes() }}">{{ $estimate ? '' : $n }}</td>
        @break
        @case('deadline')
    <td class="{{ $col->classes() }}">
        @if ($estimate)@unless ($estimate->isGiven())<span class="cell-timer"><x-ui.icon name="timer" class="size-3.5"/><x-offer.estimate-left :estimate="$estimate" word=""/></span>@endunless
        @elseif ($gallery)<x-offer.gallery-left :offer="$offer"/>
        @elseif ($left !== null && $left > 0)<span class="cell-timer {{ $offer->isEndingSoon() ? 'text-urgent' : 'text-ink-muted' }}"><x-ui.icon name="timer" class="size-3.5"/><span class="nums" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="Приём закрыт" data-timer-coarse-value="true" data-timer-word-value="">{{ \App\Support\Ago::left($offer->bids_close_at, '') }}</span></span>
        @elseif ($offer->state === \App\Offers\OfferState::Sold && $offer->seesDeal($user))<span class="text-urgent">Идёт сделка</span>
        @elseif ($bids && !$offer->bidsOpen())<span class="text-ink-dim">Приём закрыт</span>@endif
    </td>
        @break
        @case('city')
    <td class="{{ $col->classes() }}">{{ $city }}</td>
        @break
        @case('price')
    <td class="{{ $col->classes() }}">
        {{-- На оценке: «Оценить» в строке, оценил — его сумма; под ней заявленная — ориентир (закупочной на сайте нет). --}}
        {{-- Корректировка — цена XCar числом, как цена в строке; оценка — кнопкой. Под ними заявленная. --}}
        @if ($estimate)
            {{-- Как цена соседних строк: «заявленная → …» — «Нужна оценка» или цена XCar; оценил — его сумма. --}}
            @php $from = $estimate->showsDeclared() ? $offer->declaredPrice() : null; @endphp
            @if ($estimate->isGiven())<span>ваша {{ $price::money($estimate->amount) }}</span>
            @else
                @if ($from)<span class="text-ink-muted">{{ $price::money($from) }} → </span>@endif{!! $estimate->isCorrection() ? e($price::money($estimate->base_price)) : '<span class="text-urgent">Нужна оценка</span>' !!}
                @unless ($estimate->isCorrection())<a href="{{ $href }}" class="btn btn-get mt-1">Оценить</a>@endunless
            @endif
        @elseif ($gallery)<a href="{{ $myInterest ? $href : $href.(str_contains($href, '?') ? '&' : '?').'interes=1' }}" class="btn btn-get {{ $myInterest ? 'btn-get-done' : ($offer->acceptsInterest() ? '' : 'btn-get-quiet') }}">{{ $user->isBuyer() ? ($myInterest ? 'Сообщим о продаже' : 'Сообщить о продаже') : ($myInterest ? 'Интерес отмечен' : 'Проявить интерес') }}</a>
        @elseif ($price->shown())@if ($price->withFrom())<span class="hidden text-ink-muted lg:inline">{{ $price::money($price->from) }} → </span>@endif{{ $price::money($price->to) }}@elseif (!$gallery)<span class="text-accent-text">Узнать</span>@endif
        {{-- Заявленная (менеджеру — закупочная для него) под ценой, пока стрелка «от → до» не влезает. --}}
        @if (! $estimate && $price->shown() && $price->withFrom())<span class="cell-sub lg:hidden">{{ $price::money($price->from) }}</span>@endif
        @if ($myBid?->hasPendingCounter())<span class="cell-sub text-accent-text">контр {{ $price::money($myBid->counter_amount) }}</span>@elseif ($myBid)<span class="cell-sub">{{ $myBid->isGarage() ? 'в гараж' : 'ваша '.$price::money($myBid->amount) }}</span>@endif
    </td>
        @break
        @case('since')
    <td class="{{ $col->classes() }}">{!! \App\Support\Ago::time($since) !!}</td>
        @break
        @endswitch
    @endforeach
</tr>
