@php
    $user = auth()->user();
    $gallery = $offer->isGallery();
    $prices = !$gallery && ($user?->role->canSeePrices() ?? false);
    $back = $context?->backUrl() ?? ($gallery ? '/galereya' : '/');
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
<x-ui.shell :title="$offer->titleWithYear()" :trail="[['Главная', '/'], [$gallery ? 'Галерея' : 'Предложения', $back], ['Оффер '.$offer->number]]" data-offer-page="{{ $offer->number }}">
    <x-slot:actions>
        @auth<x-offer.share :offer="$offer" icon/>@endauth
        @auth<x-offer.favorite :offer="$offer" variant="compact"/>@endauth
        <x-ui.nav-arrows class="ml-auto sm:ml-0"
            :prev="$position['prev'] ? $context->offerUrl($position['prev']) : null"
            :next="$position['next'] ? $context->offerUrl($position['next']) : null"
            :back="$back" :index="$position['index']" :total="$position['total']"/>
    </x-slot:actions>

    <div class="-mt-3 mb-6 flex flex-wrap gap-1.5"><x-offer.tags :offer="$offer" :facts="false"/></div>

    <div class="grid gap-8 lg:grid-cols-[1fr_22rem]">
        <div class="lg:col-start-1 lg:row-start-1">
            <x-offer.gallery :photos="$photos" :alt="$offer->titleWithYear()"/>
        </div>

        <aside class="lg:col-start-2 lg:row-span-2 lg:row-start-1 lg:sticky lg:top-32 lg:self-start">
            <x-offer.deal-box :offer="$offer" :my-bid="$myBid" :my-interest="$myInterest" :chat="$chat" :chats-count="$chatsCount"/>
        </aside>

        <div class="lg:col-start-1 lg:row-start-2">
            @if ($prices && $offer->asking_price)
                <div class="mb-8 lg:hidden">
                    <div class="nums text-[26px] leading-none">{{ number_format($offer->asking_price, 0, '', ' ') }} ₽ @if ($offer->prices_include_vat)<span class="text-sm font-normal text-ink-muted">с НДС</span>@endif</div>
                </div>
            @endif

            <section>
                <h2 class="text-xl">Характеристики</h2>
                <dl class="mt-4 grid grid-cols-2 gap-x-8 gap-y-3 sm:grid-cols-3">
                    @foreach ($facts as $label => $value)
                        <div class="min-w-0"><dt class="text-sm text-ink-dim">{{ $label }}</dt><dd class="nums mt-0.5 break-words font-medium" @if ($label === 'Где сейчас') data-offer-place @endif>{{ $value }}</dd></div>
                    @endforeach
                </dl>
            </section>

            @if ($offer->description)
                <section class="mt-8">
                    <h2 class="text-xl">Описание</h2>
                    <p class="mt-4 whitespace-pre-line text-ink-muted">{{ $offer->description }}</p>
                </section>
            @endif
        </div>
    </div>

    @if ($chat)
        <div data-controller="sheet" data-action="chat:open@window->sheet#open" class="contents">
            <x-ui.sheet id="chat" title="Чат по № {{ $offer->number }}" :open="request()->boolean('chat')" wide>
                <x-chat.box :chat="$chat" :messages="$chat->messages()->with(['author', 'files'])->get()" :user="$user"/>
            </x-ui.sheet>
        </div>
    @endif

    <x-offer.action-bar :offer="$offer" :my-bid="$myBid" :my-interest="$myInterest" :chat="$chat"/>
</x-ui.shell>
