{{-- Машина гаража в плитках и строках (10.10.2026) — раскладка x-park.card и x-offer.crm-card: кадры листаются, название
     с годом, под ним одной строкой текста этап цветным словом, когда и чья (`App\Garage\CarLine`), в CRM ещё логотип
     вендора с номером убытка; справа главное число этапа и подпись под ним. Кнопок нет: вся карточка — ссылка. detail —
     в карточку рядом со списком (CRM), иначе на страницу (сайт: машина, вывоз). Строка этапа — своя фраза, не режется. --}}
@props(['offer', 'href', 'word', 'tone' => 'text-ink-muted', 'when' => null, 'value' => null, 'caption' => null, 'who' => null, 'detail' => false, 'vendor' => false])
@php
    use App\Support\Money;
    $n = $offer->number;
    $link = $detail ? 'data-detail-link data-turbo-frame="detail" data-turbo-prefetch="false"' : '';
    $main = $offer->mainPhoto();
    $photos = $offer->visiblePhotos()->reject(fn ($p) => $main && $p->is($main))->prepend($main)->filter()->take(6)->values();
    $tail = implode(', ', array_filter([$when, $who]));
@endphp
<article id="garage-card-{{ $n }}" @if ($detail) data-detail-key="{{ $n }}" @endif {{ $attributes->class(['card card--garage group']) }}>
    <a href="{{ $href }}" class="card-link" aria-hidden="true" tabindex="-1" {!! $link !!}></a>
    <x-ui.card-media :photos="$photos" :href="$href" :link="$link"/>
    <div class="card-body">
        <div class="card-title">
            <a href="{{ $href }}" {!! $link !!} class="flex min-w-0 flex-1 items-center gap-[.25em] leading-snug hover:text-accent-text"><span class="line-clamp-1 min-w-0">{{ $offer->titleWithYear() }}</span><x-car.keys :count="$offer->keys_count"/></a>
        </div>
    </div>
    <div class="card-extra">
        <span class="card-sub garage-sub"><span class="{{ $tone }}">{{ $word }}</span>@if ($tail), {{ $tail }}@endif <x-vendor.ref :vendor-id="$vendor ? $offer->vendor_id : null" :ref="$vendor ? $offer->claim_ref : null" copy/></span>
    </div>
    <div class="card-aside">
        @if ($caption)
            <span class="garage-money">
                <span class="nums block">{{ Money::exact($value) }}</span>
                <span class="block text-sm text-ink-muted">{{ $caption }}</span>
            </span>
        @endif
    </div>
</article>
