{{-- Строка таблицы предложений на сайте (менеджер, покупатель, избранное). Ячейка в два этажа: название с
     «рекомендуем», под ним номер, приём (срок с иконкой секундомера, 07.10.2026: «осталось» и голое «12 д 5 ч» владельцу не нравились)
     и город; от 640 номер, приём и город встают
     столбцами. Справа цена так, как видит человек, или «Узнать», под ней заявленная, на телефоне без «сколько прошло» (07.10.2026, владелец: «зачем писать 2 дн»), на ПК — столбцом. Нажатие — карточка. --}}
@props(['offer', 'context' => null, 'deadline' => true, 'estimate' => null])
@php
    $user = auth()->user();
    $n = $offer->number;
    $gallery = $offer->isGallery();
    $price = \App\Offers\PriceView::for($offer, $user);
    $bids = $user?->bidsOnSite() ?? false;
    $left = $gallery || !$bids ? null : $offer->secondsLeft();
    // Новое и горящее — точкой перед названием, как непрочитанное (владелец 10.10.2026); горящее важнее.
    // Отдано на оценку (11.10.2026): ход менеджера — оранжевой точкой; у черновика номер временный, его не пишем.
    $tone = match (true) {
        $estimate !== null => $estimate->isGiven() ? null : 'hot',
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
<tr data-detail-key="{{ $n }}" data-search-row id="offer-{{ $n }}" data-offer-number="{{ $n }}">
    <td class="grow">
        <x-ui.row-link :key="$n"><span class="cell-title">@if ($tone)<span class="card-tone card-tone--{{ $tone }}" aria-label="{{ $estimate ? 'Нужно оценить' : ($tone === 'hot' ? 'Горящее' : 'Новое') }}"></span>@endif{{ $offer->titleWithYear() }}@if ($offer->recommended)<x-offer.recommended/>@endif</span></x-ui.row-link>
        <span class="cell-sub">
            @unless ($estimate)<span class="sm:hidden">№ {{ $n }}</span>@endunless
            @if ($estimate)@unless ($estimate->isGiven())<span class="cell-timer sm:hidden"><x-ui.icon name="timer" class="size-3.5"/><x-offer.estimate-left :estimate="$estimate" word=""/></span>@endunless
            @elseif ($gallery)<x-offer.gallery-left :offer="$offer" word="" class="sm:hidden"/>
            @elseif ($left !== null && $left > 0)<span class="cell-timer sm:hidden {{ $offer->isEndingSoon() ? 'text-urgent' : 'text-ink-muted' }}"><x-ui.icon name="timer" class="size-3.5"/><span class="nums" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="приём закрыт" data-timer-coarse-value="true" data-timer-word-value="">{{ \App\Support\Ago::left($offer->bids_close_at, '') }}</span></span>
            @elseif ($offer->state === \App\Offers\OfferState::Sold)<span class="sm:hidden text-urgent">идёт сделка</span>
            @elseif ($bids && !$offer->bidsOpen())<span class="sm:hidden">приём закрыт</span>@endif
            @if ($city)<span class="sm:hidden">{{ $city }}</span>@endif
        </span>
    </td>
    <td class="cell-dim nums hidden sm:table-cell">{{ $estimate ? '' : $n }}</td>
    @if ($deadline)
    <td class="hidden sm:table-cell">
        @if ($estimate)@unless ($estimate->isGiven())<span class="cell-timer"><x-ui.icon name="timer" class="size-3.5"/><x-offer.estimate-left :estimate="$estimate" word=""/></span>@endunless
        @elseif ($gallery)<x-offer.gallery-left :offer="$offer"/>
        @elseif ($left !== null && $left > 0)<span class="cell-timer {{ $offer->isEndingSoon() ? 'text-urgent' : 'text-ink-muted' }}"><x-ui.icon name="timer" class="size-3.5"/><span class="nums" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="Приём закрыт" data-timer-coarse-value="true" data-timer-word-value="">{{ \App\Support\Ago::left($offer->bids_close_at, '') }}</span></span>
        @elseif ($offer->state === \App\Offers\OfferState::Sold)<span class="text-urgent">Идёт сделка</span>
        @elseif ($bids && !$offer->bidsOpen())<span class="text-ink-dim">Приём закрыт</span>@endif
    </td>
    @endif
    <td class="cell-dim col-detail-hide hidden lg:table-cell">{{ $city }}</td>
    <td class="num nums">
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
        @elseif ($gallery)<a href="{{ $myInterest ? $href : $href.(str_contains($href, '?') ? '&' : '?').'interes=1' }}" class="btn btn-get {{ $myInterest ? 'btn-get-done' : ($offer->acceptsInterest() ? '' : 'btn-get-quiet') }}">{{ $myInterest ? 'Интерес отмечен' : 'Проявить интерес' }}</a>
        @elseif ($price->shown())@if ($price->withFrom())<span class="hidden text-ink-muted lg:inline">{{ $price::money($price->from) }} → </span>@endif{{ $price::money($price->to) }}@elseif (!$gallery)<span class="text-accent-text">Узнать</span>@endif
        {{-- Заявленная (менеджеру — закупочная для него) под ценой, пока стрелка «от → до» не влезает. --}}
        @if (! $estimate && $price->shown() && $price->withFrom())<span class="cell-sub lg:hidden">{{ $price::money($price->from) }}</span>@endif
        @if ($myBid)<span class="cell-sub">{{ $myBid->isGarage() ? 'в гараж' : 'ваша '.$price::money($myBid->amount) }}</span>@endif
    </td>
    <td class="cell-dim num col-detail-hide hidden sm:table-cell">{!! \App\Support\Ago::time($since) !!}</td>
</tr>
