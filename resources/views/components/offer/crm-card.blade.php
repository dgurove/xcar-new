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
    // Черновик из письма догружается (`AutoScan::states`): ход строкой, полоса бежит по карточке, «Оценить» — после.
    $arriving = $arrive['state'] ?? null;
    $busy = in_array($arriving, ['ghost', 'files', 'reading'], true);
    // Что сказать о плитке — тот же расчёт, что у строки таблицы (`Offers\OfferRow`, 11.11.2026): после продажи — этап сделки
    // с менеджером, подтверждения — «2 подтв., 1 в гараж»; выбор пачкой — галочкой в углу кадра, не готовое — «нет фото».
    $r = \App\Offers\OfferRow::for($offer, auth()->user(), $gallery, $checkable, $busy);
    [$admin, $draft, $unpriced, $rate, $timer, $vendor, $missing] = [$r->admin, $r->draft, $r->unpriced, $r->rate, $r->timer, $r->vendor, $r->missing];
    [$corrections, $correcting, $proposes] = [$r->corrections, $r->correcting(), $r->proposes()];
    // Кружков «новое» и «горящее» в CRM нет (30.10.2026, владелец: «бесят»); остаются «в минус» и срок обязывающего.
    $mark = $admin && ! $gallery && ! in_array($crmTone = $offer->crmTone(), ['fresh', 'hot'], true) ? $crmTone : null;
@endphp
{{-- data-todo — как у строки таблицы (OfferController::todo): по таким карточка идёт к следующей и в плитках. --}}
<article data-search-row id="admin-offer-{{ $n }}" data-offer-number="{{ $n }}" data-detail-key="{{ $n }}" class="card group {{ $mark ? 'card--'.$mark : '' }} {{ $arrive ? 'arrive arrive--offer' : '' }} {{ $busy ? 'arrive--busy arrive--'.$arriving : '' }}" @if ($r->todo) data-todo @endif @if ($offer->claim_ref_key) data-flip="c:{{ $offer->claim_ref_key }}" @endif @if ($arrive) data-controller="arrive" data-arrive-state-value="{{ $arriving }}" data-arrive-subject-value="o:{{ $offer->id }}" @endif>
    <a href="{{ $href }}" class="card-link" aria-hidden="true" tabindex="-1" {!! $peek !!}></a>
    @if ($checkable && (! $missing || request()->query('preset') === 'done'))<label class="card-check card-check--on" aria-label="Выбрать"><input type="checkbox" id="pick-{{ $n }}" data-turbo-permanent name="offers[]" value="{{ $n }}" form="offers-pick" data-pick-target="box" data-group="{{ $group }}" data-action="pick#sync"><span><x-ui.icon name="check" class="size-4"/></span></label>@endif
    <x-ui.card-media :photos="$photos" :href="$href" :link="$peek"/>
    <div class="card-body">
        <div class="card-title">
            <a href="{{ $href }}" {!! $peek !!} class="flex min-w-0 flex-1 items-center gap-[.25em] leading-snug hover:text-accent-text"><span class="line-clamp-1 min-w-0">{{ $offer->titleWithYear() }}</span>@if ($offer->recommended)<x-offer.recommended/>@endif{{ "" }}@if ($offer->isUrgent())<x-offer.urgent/>@endif{{ "" }}@if ($offer->auctionPlanned())<x-offer.auction/>@endif{{ "" }}@if ($admin && $offer->proposed())<span class="ml-1.5 inline-flex shrink-0 text-accent-text" title="Предложено из заявок на приём" aria-label="Предложено из заявок на приём"><x-ui.spark class="size-4"/></span>@endif{{ "" }}@if ($admin && $offer->awaitingPark())<x-offer.awaiting/>@endif<x-ui.links :offer="$offer"/>@if ($r->noVin)<span class="shrink-0 text-sm text-danger">Нет VIN</span>@endif{{ "" }}@if ($offer->intake_closed_at && $offer->state === \App\Offers\OfferState::Draft && request()->query('preset') !== 'done')<span class="ml-1.5 shrink-0 text-sm text-urgent">приём закрыт</span>@endif{{ "" }}@if ($offer->partner_price_at)<span class="ml-1.5 shrink-0 text-sm text-ink-muted" title="Поставщик вписал цену, но ещё не отправил — может поправить">у поставщика</span>@endif<x-offer.binding :offer="$offer" icon class="shrink-0"/></a>
        </div>
    </div>
    <div class="card-extra">
        <span class="card-sub" data-controller="fitline">
            {{-- Номер первым и одним куском (fitline ужимает строку, чтобы он влез), состояние — после, его можно обрезать. --}}
            @if ($vendor || $offer->claim_ref)<span class="fit-core"><x-vendor.ref :vendor="$vendor" :ref="$offer->claim_ref" copy/></span>@endif
            @if ($timer)<span class="nums {{ $offer->isEndingSoon() ? 'text-urgent' : 'text-accent-text' }}" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="приём закрыт" data-timer-coarse-value="true" data-timer-word-value="">{{ \App\Support\Ago::left($offer->bids_close_at, '') }}</span>
            @elseif ($r->slot)<span class="text-accent-text">{{ $r->slot }}</span>
            @elseif (! $draft || filled(request('q')))<span class="{{ $r->tone }}">{{ $r->stateWord }}@if ($r->line?->who)<span class="text-ink-muted">, {{ $r->line->who }}</span>@endif</span>@endif
            @if ($r->countWord)<span class="{{ $gallery ? 'text-accent-text' : 'text-urgent' }}">{{ $r->countWord }}</span>@endif
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
