{{-- Предложение в плитках и строках CRM — как ТС парковки (x-park.card): кадр, название, одна строка текста (логотип
     страховой с номером убытка — копируется нажатием, состояние или таймер приёма, подтверждения или интерес; номер
     предложения — в таблице), цена обычным текстом и город приглушённым с меткой: в плитке внизу — город слева, цена
     справа, в строке — цена справа, город под ней. Неоценённый черновик — лаймовым «оценить» (только админу): ведёт в
     окошко таблицы черновиков на этой строке, рядом закупочная. Тегов года, коробки и НДС нет — они в окошке и на странице. Кнопок нет: вся
     карточка — ссылка на предложение. --}}
@props(['offer', 'gallery' => false])
@php
    use App\Offers\OfferState;
    use App\Support\Money;
    $n = $offer->number;
    $href = '/offers/'.$n;
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
    $left = $gallery || ! $admin ? null : $offer->secondsLeft();
    $timer = $left !== null && $left > 0;
    $tone = match ($offer->state->tone()) { 'open' => 'text-accent-text', 'urgent' => 'text-urgent', 'danger' => 'text-danger', default => 'text-ink' };
    $count = ! $admin ? 0 : ($gallery ? (int) ($offer->interests_count ?? 0) : (int) ($offer->active_bids_count ?? 0));
@endphp
<article id="admin-offer-{{ $n }}" data-offer-number="{{ $n }}" class="card rise group">
    <a href="{{ $href }}" class="card-link" aria-hidden="true" tabindex="-1"></a>
    @if ($photos->isNotEmpty())
        <div class="card-media" data-controller="frames" data-action="cards:tick@window->frames#next cards:stop@window->frames#stop">
            <a href="{{ $href }}" class="card-strip" data-frames-target="strip" data-action="frames#click touchstart->frames#touch:passive">
                @foreach ($photos as $frame)
                    <x-offer.photo :media="$frame" :sizes="\App\Support\ListView::sizes(\App\Support\ListView::fromRequest(request()))" data-frames-target="frame"/>
                @endforeach
            </a>
            @if ($photos->count() > 1)
                <div class="card-frames">
                    <div class="pointer-events-none absolute inset-x-0 bottom-3 flex justify-center gap-1">
                        @foreach ($photos as $i => $frame)<span class="card-dot{{ $i === 0 ? ' card-dot--on' : '' }}" data-frames-target="dot"></span>@endforeach
                    </div>
                    <button type="button" class="card-arrow left-2 hidden sm:flex" data-action="frames#prev" aria-label="Предыдущее фото"><x-ui.icon name="chevron-left" class="size-[18px]"/></button>
                    <button type="button" class="card-arrow right-2 hidden sm:flex" data-action="frames#manual" aria-label="Следующее фото"><x-ui.icon name="chevron-right" class="size-[18px]"/></button>
                </div>
            @endif
        </div>
    @else
        <a href="{{ $href }}" class="card-media card-media--blank" tabindex="-1"><x-ui.car-blank/></a>
    @endif
    <div class="card-body">
        <div class="card-title">
            <a href="{{ $href }}" class="flex min-w-0 flex-1 items-center gap-[.25em] leading-snug hover:text-accent-text"><span class="line-clamp-1 min-w-0">{{ $offer->titleWithYear() }}</span>@if ($offer->recommended)<x-offer.recommended/>@endif</a>
        </div>
    </div>
    <div class="card-extra">
        <span class="card-sub" data-controller="fitline">
            {{-- Номер первым и одним куском (fitline ужимает строку, чтобы он влез), состояние — после, его можно обрезать. --}}
            @if ($vendor || $offer->claim_ref)<span class="fit-core"><x-vendor.ref :vendor="$vendor" :ref="$offer->claim_ref" copy/></span>@endif
            @if ($timer)<span class="nums {{ $offer->isEndingSoon() ? 'text-urgent' : 'text-accent-text' }}" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="приём закрыт"></span>
            @elseif ($offer->isScheduled())<span class="text-accent-text">выйдет {{ \App\Offers\Slots::phrase($offer->slot_at) }}</span>
            @elseif (! $draft)<span class="{{ $tone }}">{{ mb_strtolower($offer->state->labelFor(auth()->user())) }}</span>@endif
            @if ($count)<span class="{{ $gallery ? 'text-accent-text' : 'text-urgent' }}">{{ $count }} {{ $gallery ? \App\Support\Plural::of($count, ['интерес', 'интереса', 'интересов']) : 'подтв.' }}</span>@endif
        </span>
    </div>
    <div class="card-aside">
        @if ($offer->settlement)<x-ui.place class="card-city text-sm text-ink-dim">{{ $offer->settlement->title() }}</x-ui.place>@endif
        <span class="card-price">
            @if ($unpriced)
                @if ($rate)<a href="/?preset=draft&vid=table&peek={{ $n }}" class="text-sm text-accent-text" data-turbo-action="replace">оценить</a>@endif
                @if ($offer->floor_price)<span class="nums">{{ Money::nums($offer->floor_price) }}&nbsp;₽</span>@endif
            @elseif ($price->shown())<span class="nums">{{ $price::money($price->to) }}&nbsp;₽</span>
            @elseif ($gallery)<span class="text-sm text-accent-text">скоро</span>@endif
        </span>
    </div>
</article>
