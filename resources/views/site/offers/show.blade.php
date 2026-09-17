@php
    $user = auth()->user();
    $gallery = $offer->isGallery();
    $price = \App\Offers\PriceView::for($offer, $user);
    $prices = $price->visible;
    $back = $context?->backUrl() ?? ($gallery ? '/gallery' : '/');
    $dealInSheet = \App\Offers\DealPlacement::inSheet($offer, $user, $myInterest);
    $facts = array_filter([
        'Год' => $offer->year,
        'Пробег' => $offer->mileage !== null ? \App\Support\Money::nums($offer->mileage).' км' : null,
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
        @if ($user?->role->canShare())<x-offer.share :offer="$offer" icon/>@endif
        @if ($user?->isManager() && $offer->state->isPublic())
            <button type="button" class="btn btn-s btn-quiet btn-round hidden lg:inline-flex" data-controller="emit" data-action="emit#send" data-emit-event-param="show:open" aria-label="Показать покупателям"><x-ui.icon name="users" class="size-5"/></button>
        @endif
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

            @if ($user?->isManager() && $offer->state->isPublic())
                {{-- Менеджер: кому из его покупателей открыто (чип — и есть кнопка «закрыть», с подтверждением)
                     и кто проявил интерес. На телефоне — после цены и характеристик, в правой колонке — вторым. --}}
                <section class="box order-3 lg:order-2" data-controller="sheet" data-action="show:open@window->sheet#open">
                    <h2 class="text-lg">Покупатели</h2>
                    @if ($showings->isEmpty())
                        <button type="button" class="btn btn-accent mt-4 w-full" data-action="sheet#open"><x-ui.icon name="users" class="size-5"/> Показать покупателям</button>
                    @else
                        <div class="mt-3 flex flex-wrap gap-1.5">
                            @foreach ($showings as $s)
                                @php $who = $s->group ? 'группы «'.$s->group->name.'»' : $s->user?->shortName(); @endphp
                                <form method="post" action="/account/showings" class="contents" data-turbo-confirm="Закрыть для {{ $who }}?" data-turbo-confirm-label="Закрыть" data-turbo-confirm-text="{{ $offer->titleWithYear() }} пропадёт из ленты.">
                                    @csrf @method('delete')
                                    <input type="hidden" name="offer" value="{{ $offer->id }}">
                                    <input type="hidden" name="{{ $s->group ? 'group' : 'user' }}" value="{{ $s->group_id ?? $s->user_id }}">
                                    @if ($s->group)
                                        <button type="submit" class="chip"><x-ui.icon name="users" class="size-4"/>{{ $s->group->name }}<x-ui.icon name="x" class="size-3.5 text-ink-dim"/></button>
                                    @elseif ($s->user)
                                        <button type="submit" class="chip person"><x-ui.avatar :user="$s->user" :size="20"/><span class="truncate">{{ $s->user->shortName() }}</span><x-ui.icon name="x" class="size-3.5 text-ink-dim"/></button>
                                    @endif
                                </form>
                            @endforeach
                            <button type="button" class="chip rounded-full !py-0.5 text-accent-text" data-action="sheet#open"><x-ui.icon name="plus" class="size-4"/> Показать…</button>
                        </div>
                    @endif
                    @if ($buyerInterests->isNotEmpty())
                        <div class="mt-4 flex flex-col gap-2">
                            @foreach ($buyerInterests as $interest)
                                @include('cabinet.buyers.interest-row', ['interest' => $interest, 'person' => true, 'car' => false])
                            @endforeach
                        </div>
                    @endif
                    <x-ui.sheet id="show" title="Показать покупателям" wide>
                        <turbo-frame id="show-frame" src="/account/showings/new?offers[]={{ $offer->id }}&back={{ urlencode(request()->getRequestUri()) }}" loading="lazy" target="_top" class="block min-h-40">
                            <x-ui.skeleton :rows="3"/>
                        </turbo-frame>
                    </x-ui.sheet>
                </section>
            @endif

            @if ($dealInSheet && $price->shown())
                <div class="nums order-1 text-[26px] leading-none lg:hidden" data-controller="fit">{{ $price::money($price->to) }}&nbsp;₽@if ($price->vat) <span class="text-[.55em] font-normal text-ink-muted">с НДС</span>@endif</div>
            @endif

            @if ($facts)
                <section class="box order-2 lg:order-3">
                    <h2 class="text-lg">Характеристики</h2>
                    <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-3">
                        @foreach ($facts as $label => $value)
                            <div @class(['min-w-0', 'col-span-2' => in_array($label, ['VIN', 'Осмотр', 'Повреждения'], true)])>
                                <dt class="text-sm text-ink-dim">{{ $label }}</dt>
                                <dd @class(['mt-0.5 font-medium', 'nums whitespace-nowrap' => $label === 'VIN', 'break-words' => $label !== 'VIN']) @if ($label === 'Где сейчас') data-offer-place @endif>
                                    @if ($label === 'VIN')<x-ui.vin-code :vin="$value" :copy="$offer->show_vin"/>
                                    @elseif (in_array($label, ['Город', 'Где сейчас', 'Осмотр'], true))<x-ui.place>{{ $value }}</x-ui.place>
                                    @else{{ $value }}@endif
                                </dd>
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
            <x-ui.sheet id="chat" :title="$manager?->shortName() ?? \App\Chats\Chat::PLATFORM" :open="request()->boolean('chat')" wide>
                {{-- Лента грузится при открытии шторки (ленивый фрейм видит showModal), страница и стрелки к соседям легче. --}}
                <turbo-frame id="offer-chat" src="/offers/{{ $offer->number }}/chat" loading="{{ request()->boolean('chat') ? 'eager' : 'lazy' }}" target="_top" class="block min-h-32">
                    <x-ui.skeleton :rows="2"/>
                </turbo-frame>
            </x-ui.sheet>
        </div>
    @endif

    <x-offer.action-bar :offer="$offer" :my-bid="$myBid" :my-interest="$myInterest" :chat="$chat" :can-chat="$canChat"/>
</x-ui.shell>
