{{-- Предложение в плитках и строках CRM — как ТС парковки (x-park.card): кадр, название, одна строка текста (логотип
     страховой с номером убытка, номер предложения, состояние или таймер приёма, подтверждения или интерес), справа цена
     и сколько прошло. Номера у черновика нет — он ещё не выставлен; неоценённый — лаймовым «оценить»: ведёт в окошко
     таблицы черновиков на этой строке. Тегов года, коробки и НДС нет — они в окошке и на странице. Кнопок нет: вся
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
    $unpriced = $draft && ! $offer->asking_price;
    $left = $gallery ? null : $offer->secondsLeft();
    $timer = $left !== null && $left > 0;
    $tone = match ($offer->state->tone()) { 'open' => 'text-accent-text', 'urgent' => 'text-urgent', 'danger' => 'text-danger', default => 'text-ink' };
    $count = $gallery ? (int) ($offer->interests_count ?? 0) : (int) ($offer->active_bids_count ?? 0);
    $since = $gallery ? $offer->created_at : ($offer->published_at ?? $offer->updated_at);
@endphp
<article id="admin-offer-{{ $n }}" data-offer-number="{{ $n }}" class="card rise group">
    <a href="{{ $href }}" class="card-link" aria-hidden="true" tabindex="-1"></a>
    @if ($photos->isNotEmpty())
        <div class="card-media" data-controller="frames" data-action="cards:tick@window->frames#next cards:stop@window->frames#stop">
            <a href="{{ $href }}" class="card-strip" data-frames-target="strip" data-action="frames#click touchstart->frames#touch:passive">
                @foreach ($photos as $frame)
                    <x-offer.photo :media="$frame" sizes="(min-width: 1024px) 320px, (min-width: 640px) 45vw, 100vw" data-frames-target="frame"/>
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
            {{-- Номера первыми и одним куском (fitline ужимает строку, чтобы они влезли), состояние — после, его можно обрезать. --}}
            <span class="fit-core"><x-vendor.ref :vendor="$vendor" :ref="$offer->claim_ref"/>@unless ($draft)<span class="nums">№ {{ $n }}</span>@endunless</span>
            @if ($timer)<span class="nums {{ $offer->isEndingSoon() ? 'text-urgent' : 'text-accent-text' }}" data-controller="timer" data-timer-until-value="{{ $offer->bids_close_at->toIso8601String() }}" data-timer-done-value="приём закрыт"></span>
            @elseif (! $draft)<span class="{{ $tone }}">{{ mb_strtolower($offer->state->label()) }}</span>@endif
            @if ($count)<span class="{{ $gallery ? 'text-accent-text' : 'text-urgent' }}">{{ $count }} {{ $gallery ? \App\Support\Plural::of($count, ['интерес', 'интереса', 'интересов']) : 'подтв.' }}</span>@endif
        </span>
    </div>
    <div class="card-aside">
        @if ($unpriced)
            <a href="/?preset=draft&vid=table&peek={{ $n }}" class="text-sm text-accent-text" data-turbo-action="replace">оценить</a>
            @if ($offer->floor_price)<span class="nums text-sm text-ink-dim">{{ Money::nums($offer->floor_price) }}</span>@endif
        @else
            @if ($price->shown())<span class="nums">{{ $price::money($price->to) }}&nbsp;₽</span>
            @elseif ($gallery)<span class="text-sm text-accent-text">скоро</span>@endif
            <span class="text-sm text-ink-dim">{!! \App\Support\Ago::time($since) !!}</span>
        @endif
    </div>
</article>
