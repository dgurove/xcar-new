{{-- Строка сделки в сгруппированном списке: фото, ТС, под ним логотип вендора с номером убытка (копируется), номер, покупатель (person=false — на его карточке
     не нужен) и чей ход с часами; справа сумма, под ней этап или состояние словом;
     на телефоне этап идущей сделки не показан — чей ход и часы под названием. --}}
@php
    $person ??= true;
    $offer = $deal->offer;
    $position = $offer->position();
    // Слово этапа — `DealLine` (одно на таблицу и строки); у идущей без своего тона — рыжим, когда ход наш.
    $line = \App\Offers\DealLine::of($deal, $position, \App\Billing\DealDebt::of($deal));
    [$word, $tone] = [$line->word, $line->tone ?: ($position?->needsUs() ? 'text-urgent' : 'text-ink-muted')];
@endphp
{{-- Сделка живёт в CRM: с сайта (карточка человека /account/users) — с хостом, иначе 404. --}}
<a href="{{ \App\Support\Surface::current() === \App\Support\Surface::Crm ? '/work/deals/'.$deal->id : \App\Support\Surface::Crm->url('/work/deals/'.$deal->id) }}" class="row" data-search-row>
    <div class="row-photo"><x-offer.photo :media="$offer->mainPhoto()" sizes="64px"/></div>
    <div class="min-w-0 flex-1">
        <div class="truncate">{{ $offer->titleWithYear() }}</div>
        <div class="row-sub">
            <x-vendor.ref :vendor-id="$offer->vendor_id" :ref="$offer->claim_ref" copy/>
            <span class="nums">№ {{ $offer->number }}</span>
            @if ($deal->isAuction())<span>Аукцион</span>@elseif ($person && $deal->buyer)<span>{{ $deal->buyer->name }}</span>@endif
        </div>
        @if ($deal->state === \App\Offers\DealState::Active && $position)<x-route.clock :position="$position" side="staff" class="mt-0.5 line-clamp-2 sm:truncate"/>@endif
    </div>
    <div class="shrink-0 text-right">
        <div class="nums">{{ $deal->isGarage() ? 'В гараж' : ($deal->amount === null ? '' : \App\Support\Money::rub($deal->amount)) }}</div>
        <x-offer.margin :m="$deal->marginMark()" class="!ml-0 block"/>
        <div class="max-w-40 truncate text-sm sm:max-w-64 {{ $tone }} @if ($deal->state === \App\Offers\DealState::Active && $position) max-sm:hidden @endif">{{ $word }}</div>
    </div>
</a>
