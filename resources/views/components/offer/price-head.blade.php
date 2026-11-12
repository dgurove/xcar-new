{{-- Цена машины крупно: «497 000 → 640 000 ₽» (заявленная → продажи, `PriceView`), «с НДС», заявленная строкой ниже.
     Одна на окно машины сайта (`x-offer.deal-body`) и шаг «Цена для покупателя» шторки подтверждения (`x-offer.bid-choice`,
     12.11.2026). Слот `aside` — справа в строке цены (крестик листа). --}}
@props(['price', 'aside' => null])
@if ($price->shown())
    <div {{ $attributes }}>
        <div class="flex items-start gap-2">
            <div class="nums min-w-0 flex-1 text-[32px] font-bold leading-tight" data-controller="fit">@if ($price->withFrom())<span class="text-[.7em] text-ink-muted">{{ $price::money($price->from) }}&nbsp;→</span> @endif{{ $price::money($price->to) }}&nbsp;₽@if ($price->vat) <span class="text-[.45em] font-normal text-ink-muted">с НДС</span>@endif</div>
            {{ $aside }}
        </div>
        @if ($price->declared)<div class="mt-2"><span class="nums text-sm text-ink-dim">заявленная {{ $price::money($price->declared) }}</span></div>@endif
    </div>
@endif
