{{-- Интерес покупателя: строки с фото и ценой; проданное или закрытое — с пометкой, ссылки нет. --}}
<x-ui.cabinet title="Интерес">
    @if ($interests->isEmpty())
        <x-ui.empty href="/offers" link="В предложения">Вы ещё ничего не отметили</x-ui.empty>
    @else
        <x-ui.toolbar :sort="$sort" name="interests" class="mb-4"/>
        <div class="list">
            @foreach ($interests as $interest)
                @php
                    $offer = $interest->offer;
                    $open = in_array($offer->id, $live, true);
                    $tag = $open ? null : ($offer->state === \App\Offers\OfferState::Sold || $offer->state === \App\Offers\OfferState::Delivered ? 'Продан' : 'Снят');
                    $price = \App\Offers\PriceView::for($offer, auth()->user());
                    // Из галереи: пока черновик — ждём продажи, цены нет ни у кого (12.10.2026: цена утекала мимо `PriceView`).
                    $status = $offer->isGallery() ? 'сообщим о продаже' : ($interest->state === \App\Offers\InterestState::New ? ($manager ? $manager->shortName().' свяжется' : 'Менеджер свяжется') : $interest->state->label());
                @endphp
                <{{ $open ? 'a' : 'div' }} @if ($open) href="/offers/{{ $offer->number }}" @endif class="row {{ $open ? '' : 'opacity-70' }}">
                    <span class="row-photo"><x-offer.photo :media="$offer->mainPhoto()" sizes="72px"/></span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                            <span class="truncate font-medium">{{ $offer->titleWithYear() }}@if ($offer->recommended)<x-offer.recommended/>@endif</span>
                            @if ($tag)<x-ui.state tone="closed">{{ $tag }}</x-ui.state>@endif
                        </div>
                        <div class="mt-0.5 flex flex-wrap items-center">
                            <span class="fact">{{ $status }}</span>
                            <span class="fact nums">{{ $interest->created_at->translatedFormat('j M') }}</span>
                        </div>
                        @if ($interest->comment)<p class="mt-1.5 text-sm text-ink-muted">{{ $interest->comment }}</p>@endif
                    </div>
                    @if ($open && $price->shown())<span class="nums shrink-0 text-sm font-semibold">{{ \App\Support\Money::rub($price->to) }}</span>@endif
                </{{ $open ? 'a' : 'div' }}>
            @endforeach
        </div>
        <x-ui.pager :of="$interests" :sizes="[]"/>
    @endif
</x-ui.cabinet>
