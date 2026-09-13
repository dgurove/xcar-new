@php
    $user = auth()->user();
    $gallery = $offer->isGallery();
    $prices = !$gallery && ($user?->role->canSeePrices() ?? false);
    $back = $context?->backUrl() ?? ($gallery ? '/galereya' : '/');
    $dealInSheet = \App\Offers\DealPlacement::inSheet($offer, $user, $myInterest);
    $facts = array_filter([
        'Год' => $offer->year,
        'Пробег' => $offer->mileage !== null ? number_format($offer->mileage, 0, '', ' ').' км' : null,
        'Кузов' => $offer->body?->label(), 'КПП' => $offer->transmission?->label(), 'Привод' => $offer->drive?->label(),
        'Топливо' => $offer->fuel?->label(), 'Объём' => $offer->engine_volume ? $offer->engine_volume.' см³' : null,
        'Мощность' => $offer->engine_power ? $offer->engine_power.' л. с.' : null, 'Цвет' => $offer->color,
        'VIN' => $offer->vinMasked(), 'Причина' => $offer->damage_cause?->label(),
        'Повреждения' => $offer->damage_zones ? implode(', ', array_map(fn ($z) => \App\Cars\DamageZone::labelOf($z), $offer->damage_zones)) : null,
        'На ходу' => $offer->is_runnable === null ? null : ($offer->is_runnable ? 'Да' : 'Нет'),
        'Ключи' => $offer->has_keys === null ? null : ($offer->has_keys ? 'Есть' : 'Нет'), 'Документы' => $offer->papers?->label(),
        'Город' => $offer->settlement?->name, 'Где сейчас' => $offer->car_place?->label(), 'Осмотр' => $offer->inspection_address,
    ], fn ($v) => $v !== null && $v !== '');
@endphp
<x-ui.shell :title="$offer->titleWithYear()" :back="[$gallery ? 'Галерея' : 'Предложения', $back]" :trail="[['Главная', '/'], [$gallery ? 'Галерея' : 'Предложения', $back], ['№ '.$offer->number]]" data-offer-page="{{ $offer->number }}">
    <x-slot:actions>
        @auth<x-offer.share :offer="$offer" icon/>@endauth
        @auth<x-offer.favorite :offer="$offer" variant="compact"/>@endauth
        <x-ui.nav-arrows class="ml-auto sm:ml-0"
            :prev="$position['prev'] ? $context->offerUrl($position['prev']) : null"
            :next="$position['next'] ? $context->offerUrl($position['next']) : null"
            :back="$back" :index="$position['index']" :total="$position['total']"/>
    </x-slot:actions>

    <div class="-mt-3 mb-6 flex flex-wrap gap-1.5"><x-offer.tags :offer="$offer" :facts="false"/></div>

    {{-- Слева галерея и описание, справа цена и характеристики: всё о машине — в первом экране. --}}
    <div class="grid gap-6 lg:grid-cols-[1fr_22rem] lg:gap-8">
        <div class="lg:col-start-1 lg:row-start-1">
            <x-offer.gallery :photos="$photos" :alt="$offer->titleWithYear()"/>
        </div>

        <aside class="flex flex-col gap-6 lg:col-start-2 lg:row-span-2 lg:row-start-1 lg:gap-8">
            <x-offer.deal-box :offer="$offer" :my-bid="$myBid" :my-interest="$myInterest" :chat="$chat" :can-chat="$canChat"/>

            @if ($dealInSheet && $prices && $offer->asking_price)
                <div class="nums text-[26px] leading-none lg:hidden" data-controller="fit">{{ number_format($offer->asking_price, 0, '', ' ') }}&nbsp;₽@if ($offer->prices_include_vat) <span class="text-[.55em] font-normal text-ink-muted">с НДС</span>@endif</div>
            @endif

            @if ($facts)
                <section class="box">
                    <h2 class="text-lg">Характеристики</h2>
                    <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-3">
                        @foreach ($facts as $label => $value)
                            <div @class(['min-w-0', 'col-span-2' => in_array($label, ['VIN', 'Осмотр', 'Повреждения'], true)])>
                                <dt class="text-sm text-ink-dim">{{ $label }}</dt>
                                <dd @class(['mt-0.5 font-medium', 'nums whitespace-nowrap' => $label === 'VIN', 'break-words' => $label !== 'VIN']) @if ($label === 'Где сейчас') data-offer-place @endif>{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </section>
            @endif
        </aside>

        @if ($offer->description)
            <section class="lg:col-start-1 lg:row-start-2">
                <h2 class="text-xl">Описание</h2>
                <p class="mt-4 whitespace-pre-line text-ink-muted">{{ $offer->description }}</p>
            </section>
        @endif
    </div>

    @if ($canChat)
        <div data-controller="sheet" data-action="chat:open@window->sheet#open" class="contents">
            <x-ui.sheet id="chat" title="Чат по № {{ $offer->number }}" :open="request()->boolean('chat')" wide>
                {{-- Лента грузится при открытии шторки (ленивый фрейм видит showModal), страница и стрелки к соседям легче. --}}
                <turbo-frame id="offer-chat" src="/offers/{{ $offer->number }}/chat" loading="{{ request()->boolean('chat') ? 'eager' : 'lazy' }}" target="_top" class="block min-h-32">
                    <x-ui.skeleton :rows="2"/>
                </turbo-frame>
            </x-ui.sheet>
        </div>
    @endif

    <x-offer.action-bar :offer="$offer" :my-bid="$myBid" :my-interest="$myInterest" :chat="$chat" :can-chat="$canChat"/>
</x-ui.shell>
