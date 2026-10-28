{{-- Предложение в плитках и строках CRM — как ТС парковки (x-park.card): кадр, название, одна строка текста (логотип
     страховой с номером убытка — копируется нажатием, состояние или таймер приёма, подтверждения или интерес; номер
     предложения — в таблице), цена обычным текстом и город приглушённым с меткой: в плитке внизу — город слева, цена
     справа, в строке — цена справа, город под ней. Неоценённый черновик — чипом «Оценить» (только админу): ведёт в
     карточка таблицы на этой строке, рядом закупочная. Тегов года, коробки и НДС нет — они в карточке и на странице. Кнопок нет: вся
     карточка — ссылка на предложение. --}}
@props(['offer', 'gallery' => false, 'checkable' => false, 'group' => null, 'arrive' => null])
@php
    use App\Offers\OfferState;
    use App\Support\Money;
    $n = $offer->number;
    // Нажатие — карточка строки рядом со списком, как в таблице (04.10.2026, владелец), а не страница.
    $href = \App\Support\Detail::url($n);
    $peek = 'data-detail-link data-turbo-frame="detail" data-turbo-prefetch="false"';
    $price = \App\Offers\PriceView::for($offer, auth()->user());
    $main = $offer->mainPhoto();
    $photos = $offer->visiblePhotos()->reject(fn ($p) => $main && $p->is($main))->prepend($main)->filter()->take(6)->values();
    $vendor = $offer->vendor_id ? \App\Vendors\Vendor::badges()->get($offer->vendor_id) : null;
    $draft = $offer->state === OfferState::Draft;
    // Подтверждения принимает только админ: модератору ни их числа, ни отсчёта приёма — открытое для него «в продаже».
    $admin = (bool) auth()->user()?->canManageCrm();
    $unpriced = $draft && ! $offer->asking_price;
    // «Оценить» — дело админа: модератор цену продажи не ставит, у него на месте слова пусто, закупочная — как была.
    $rate = $unpriced && $admin;
    // Корректировки менеджеров у оценённого черновика (11.10.2026, `x-offer.correction-chip`).
    $corrections = ! $unpriced && $draft && $admin && $offer->relationLoaded('openEstimates') ? $offer->openEstimates->filter->isCorrection() : collect();
    $correcting = $corrections->isNotEmpty();
    $proposes = $corrections->contains->proposes();
    $left = $gallery || ! $admin ? null : $offer->secondsLeft();
    $timer = $left !== null && $left > 0;
    // Новое — голубой полосой, горящее — рыжей, как на витрине (12.10.2026); только админу.
    $mark = $admin && ! $gallery ? $offer->crmTone() : null;
    $tone = match ($offer->state->tone()) { 'open' => 'text-accent-text', 'urgent' => 'text-urgent', 'danger' => 'text-danger', default => 'text-ink' };
    $count = ! $admin ? 0 : ($gallery ? (int) ($offer->interests_count ?? 0) : (int) ($offer->active_bids_count ?? 0));
    // Выбор пачкой («Оцененные», «Публикация») — галочкой в углу кадра, как в строке таблицы; не готовое к продаже —
    // без галочки, «нет фото» красным в строке текста.
    // Черновик из письма догружается (`AutoScan::states`): ход строкой, полоса бежит по карточке, «Оценить» — после.
    $arriving = $arrive['state'] ?? null;
    $busy = in_array($arriving, ['ghost', 'files', 'reading'], true);
    $rate = $rate && ! $busy;
    $missing = $checkable && $draft && ! $offer->isScheduled() ? array_map(fn ($m) => 'нет '.match ($m) { 'фотографии' => 'фото', 'марка' => 'марки', 'цена продажи' => 'цены', default => $m }, \App\Offers\Actions\ChangeOfferState::missing($offer)) : [];
@endphp
{{-- data-todo — как у строки таблицы (OfferController::todo): по таким карточка идёт к следующей и в плитках. --}}
<article data-search-row id="admin-offer-{{ $n }}" data-offer-number="{{ $n }}" data-detail-key="{{ $n }}" class="card group {{ $mark ? 'card--'.$mark : '' }} {{ $arrive ? 'arrive arrive--offer' : '' }} {{ $busy ? 'arrive--busy arrive--'.$arriving : '' }}" @if (auth()->user() && \App\Http\Admin\OfferController::todo($offer, auth()->user())) data-todo @endif @if ($offer->claim_ref_key) data-flip="c:{{ $offer->claim_ref_key }}" @endif @if ($arrive) data-controller="arrive" data-arrive-state-value="{{ $arriving }}" data-arrive-subject-value="o:{{ $offer->id }}" @endif>
    <a href="{{ $href }}" class="card-link" aria-hidden="true" tabindex="-1" {!! $peek !!}></a>
    @if ($checkable && ! $missing)<label class="card-check card-check--on" aria-label="Выбрать"><input type="checkbox" id="pick-{{ $n }}" data-turbo-permanent name="offers[]" value="{{ $n }}" form="offers-pick" data-pick-target="box" data-group="{{ $group }}" data-action="pick#sync"><span><x-ui.icon name="check" class="size-4"/></span></label>@endif
    <x-ui.card-media :photos="$photos" :href="$href" :link="$peek"/>
    <div class="card-body">
        <div class="card-title">
            <a href="{{ $href }}" {!! $peek !!} class="flex min-w-0 flex-1 items-center gap-[.25em] leading-snug hover:text-accent-text"><span class="line-clamp-1 min-w-0">{{ $offer->titleWithYear() }}</span>@if ($offer->recommended)<x-offer.recommended/>@endif{{ "" }}@if ($offer->isUrgent())<x-offer.urgent/>@endif{{ "" }}@if ($offer->auctionPlanned())<x-offer.auction/>@endif{{ "" }}@if ($admin && $offer->proposed())<span class="ml-1.5 inline-flex shrink-0 text-accent-text" title="Предложено из заявок на приём" aria-label="Предложено из заявок на приём"><x-ui.spark class="size-4"/></span>@endif{{ "" }}@if ($admin && $offer->awaitingPark())<x-offer.awaiting/>@endif<x-ui.links :offer="$offer"/>@if (! $busy && blank($offer->vin) && in_array($offer->state, [\App\Offers\OfferState::Draft, \App\Offers\OfferState::Gallery, \App\Offers\OfferState::Open], true))<span class="shrink-0 text-sm text-danger">Нет VIN</span>@endif{{ "" }}@if ($offer->intake_closed_at && $offer->state === \App\Offers\OfferState::Draft)<span class="ml-1.5 shrink-0 text-sm text-urgent">приём закрыт</span>@endif<x-offer.binding :offer="$offer" icon class="shrink-0"/></a>
        </div>
    </div>
    <div class="card-extra">
        <span class="card-sub" data-controller="fitline">
            {{-- Номер первым и одним куском (fitline ужимает строку, чтобы он влез), состояние — после, его можно обрезать. --}}
            @if ($vendor || $offer->claim_ref)<span class="fit-core"><x-vendor.ref :vendor="$vendor" :ref="$offer->claim_ref" copy/></span>@endif
            @if ($timer)<span class="nums {{ $offer->isEndingSoon() ? 'text-urgent' : 'text-accent-text' }}" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="приём закрыт" data-timer-coarse-value="true" data-timer-word-value="">{{ \App\Support\Ago::left($offer->bids_close_at, '') }}</span>
            @elseif ($offer->isScheduled())<span class="text-accent-text">выйдет {{ \App\Offers\Slots::phrase($offer->slot_at) }}</span>
            @elseif (! $draft || filled(request('q')))<span class="{{ $tone }}">{{ $offer->crmTab() }}</span>@endif
            @if ($count)<span class="{{ $gallery ? 'text-accent-text' : 'text-urgent' }}">{{ $count }} {{ $gallery ? \App\Support\Plural::of($count, ['интерес', 'интереса', 'интересов']) : 'подтв.' }}</span>@endif
            @if ($missing)<span class="text-danger">{{ implode(', ', $missing) }}</span>@endif
            @if ($admin && ! $busy)<x-offer.margin :m="$offer->marginMark()" class="!ml-0"/>@endif
            @if ($admin && $offer->reprice_pending)<span class="text-urgent">переоценка</span>@endif
            @if ($busy)<x-offer.arrive-line :arrive="$arrive"/>@endif
        </span>
    </div>
    <div class="card-aside">
        @if ($offer->settlement)<x-ui.place class="card-city text-sm text-ink-dim">{{ $offer->settlement->title() }}</x-ui.place>@endif
        <span class="card-price arrive-late {{ $admin && ($offer->marginMark()['tone'] ?? null) === 'loss' ? 'text-danger' : '' }}">
            @if ($unpriced && $rate && $offer->relationLoaded('openEstimates') && $offer->openEstimates->contains->isGiven())
                {{-- Менеджер оценил (11.10.2026): закупочная → его оценка, как «закупочная → продажи» у оценённого. --}}
                @if ($offer->floor_price)<span class="nums text-ink-muted">{{ Money::nums($offer->floor_price) }}&nbsp;→&nbsp;</span>@endif<x-offer.rate-chip :offer="$offer"/>
            @elseif ($unpriced)
                @if ($rate)<x-offer.rate-chip :offer="$offer"/>@endif
                @if ($offer->floor_price)<span class="nums">{{ Money::nums($offer->floor_price) }}&nbsp;₽</span>@endif
            {{-- Закупочная — слева от цены стрелкой (04.10.2026, владелец: «очень важно»), как в таблице. --}}
            {{-- Корректировка: своя цена менеджера — «цена → пилюля» вместо «закупочная → цена»; согласие — галочкой. --}}
            @elseif ($price->shown() && $proposes)<span class="nums text-ink-muted">{{ $price::money($price->to) }}&nbsp;→&nbsp;</span><x-offer.correction-chip :offer="$offer" part="proposal"/>
            @elseif ($price->shown())@if ($offer->floor_price)<span class="nums text-ink-muted">{{ Money::nums($offer->floor_price) }}&nbsp;→&nbsp;</span>@endif<span class="nums">{{ $price::money($price->to) }}&nbsp;₽</span>@if ($correcting) <x-offer.correction-chip :offer="$offer" part="agreed"/> <x-offer.correction-chip :offer="$offer" part="waiting"/>@endif
            @elseif ($offer->floor_price)<span class="nums text-ink-muted">{{ Money::nums($offer->floor_price) }}&nbsp;₽</span>
            @elseif ($gallery)<span class="text-sm text-accent-text">скоро</span>@endif
        </span>
    </div>
</article>
