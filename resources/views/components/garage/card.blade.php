{{-- Машина гаража в плитках и строках (10.10.2026) — раскладка x-park.card и x-offer.crm-card: кадры листаются, название
     с годом, под ним одной строкой текста этап цветным словом, когда и чья (`App\Garage\CarLine`), в CRM ещё логотип
     вендора с номером убытка; справа главное число этапа и подпись под ним. Кнопок нет: вся карточка — ссылка. detail —
     в карточку рядом со списком (CRM), иначе на страницу (сайт: машина, вывоз). Строка этапа — своя фраза, не режется.
     note — слово справа вместо числа («к менеджеру» у вывоза CRM). --}}
@props(['offer', 'href', 'word', 'tone' => 'text-ink-muted', 'when' => null, 'value' => null, 'caption' => null, 'who' => null, 'detail' => false, 'vendor' => false, 'note' => null])
@php
    use App\Support\Money;
    $n = $offer->number;
    $link = $detail ? 'data-detail-link data-turbo-frame="detail" data-turbo-prefetch="false"' : '';
    $main = $offer->mainPhoto();
    $photos = $offer->visiblePhotos()->reject(fn ($p) => $main && $p->is($main))->prepend($main)->filter()->take(6)->values();
    $badge = $vendor && $offer->vendor_id ? \App\Vendors\Vendor::badges()->get($offer->vendor_id) : null;
    $tail = implode(', ', array_filter([$when, $who]));
@endphp
<article id="garage-card-{{ $n }}" @if ($detail) data-detail-key="{{ $n }}" @endif {{ $attributes->class(['card card--garage group']) }}>
    <a href="{{ $href }}" class="card-link" aria-hidden="true" tabindex="-1" {!! $link !!}></a>
    @if ($photos->isNotEmpty())
        <div class="card-media" data-controller="frames" data-action="cards:tick@window->frames#next cards:stop@window->frames#stop">
            <a href="{{ $href }}" {!! $link !!} class="card-strip" data-frames-target="strip" data-action="frames#click touchstart->frames#touch:passive">
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
        <a href="{{ $href }}" {!! $link !!} class="card-media card-media--blank" tabindex="-1"><x-ui.car-blank/></a>
    @endif
    <div class="card-body">
        <div class="card-title">
            <a href="{{ $href }}" {!! $link !!} class="flex min-w-0 flex-1 items-center gap-[.25em] leading-snug hover:text-accent-text"><span class="line-clamp-1 min-w-0">{{ $offer->titleWithYear() }}</span></a>
        </div>
    </div>
    <div class="card-extra">
        <span class="card-sub garage-sub"><span class="{{ $tone }}">{{ $word }}</span>@if ($tail), {{ $tail }}@endif @if ($badge || ($vendor && $offer->claim_ref))<x-vendor.ref :vendor="$badge" :ref="$offer->claim_ref" copy/>@endif</span>
    </div>
    <div class="card-aside">
        @if ($caption)
            <span class="garage-money">
                <span class="nums block">{{ Money::exact($value) }}</span>
                <span class="block text-sm text-ink-muted">{{ $caption }}</span>
            </span>
        @elseif ($note)
            <span class="garage-money text-sm text-ink-muted">{{ $note }}</span>
        @endif
    </div>
</article>
