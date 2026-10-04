@php
    $user = auth()->user();
    $gallery = $offer->isGallery();
    $price = \App\Offers\PriceView::for($offer, $user);
    $prices = $price->visible;
    $back = $context?->backUrl() ?? ($gallery ? '/gallery' : '/offers');
    $dealInSheet = \App\Offers\DealPlacement::inSheet($offer, $user, $myInterest);
@endphp
<x-ui.shell :title="$offer->titleWithYear()" :back="[$gallery ? 'Галерея' : 'Предложения', $back]" :trail="[['Главная', '/'], [$gallery ? 'Галерея' : 'Предложения', $back], ['№ '.$offer->number]]" data-offer-page="{{ $offer->number }}">
    <x-slot:actions>
        @if ($user?->canShare())<x-offer.share :offer="$offer" icon/>@endif
        @auth<x-offer.favorite :offer="$offer" variant="compact"/>@endauth
        <x-ui.nav-arrows class="ml-auto sm:ml-0"
            :prev="$position['prev'] ? $context->offerUrl($position['prev']) : null"
            :next="$position['next'] ? $context->offerUrl($position['next']) : null"
            :back="$back" :index="$position['index']" :total="$position['total']"/>
    </x-slot:actions>

    <div class="-mt-3 mb-6 flex flex-wrap gap-1.5">@if ($offer->recommended)<x-offer.recommended label/>@endif<x-offer.tags :offer="$offer" :facts="false"/></div>

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
                    <h2 class="box-title">Покупатели</h2>
                    @if ($showings->isEmpty())
                        <button type="button" class="btn btn-accent mt-4 w-full" data-action="sheet#open"><x-ui.icon name="users" class="size-5"/> Показать покупателям</button>
                    @else
                        <div class="mt-3 flex flex-wrap gap-1.5">
                            @foreach ($showings as $s)
                                @php $who = $s->group ? 'группы «'.$s->group->name.'»' : $s->user?->shortName(); @endphp
                                <form method="post" action="/buyers/showings" class="contents" data-turbo-confirm="Закрыть для {{ $who }}?" data-turbo-confirm-label="Закрыть" data-turbo-confirm-text="{{ $offer->titleWithYear() }} пропадёт из ленты">
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
                        <div class="list mt-4">
                            @foreach ($buyerInterests as $interest)
                                @include('cabinet.buyers.interest-row', ['interest' => $interest, 'person' => true, 'car' => false])
                            @endforeach
                        </div>
                    @endif
                    <x-ui.sheet id="show" title="Показать покупателям" wide>
                        <turbo-frame id="show-frame" src="/buyers/showings/new?offers[]={{ $offer->id }}&back={{ urlencode(request()->getRequestUri()) }}" loading="lazy" target="_top" class="block min-h-40">
                            <x-ui.skeleton :rows="3"/>
                        </turbo-frame>
                    </x-ui.sheet>
                </section>
            @endif

            @if ($dealInSheet && $price->shown())
                <div class="nums order-1 text-2xl leading-none lg:hidden" data-controller="fit">@if ($price->withFrom())<span class="text-[.7em] text-ink-muted">{{ $price::money($price->from) }}&nbsp;→</span> @endif{{ $price::money($price->to) }}&nbsp;₽@if ($price->vat) <span class="text-[.55em] font-normal text-ink-muted">с НДС</span>@endif</div>
            @endif

            <x-offer.facts :offer="$offer" class="order-2 lg:order-3"/>
        </aside>

        @if ($offer->description)
            <section class="lg:col-start-1 lg:row-start-2">
                <h2 class="list-head">Описание</h2>
                <p class="whitespace-pre-line text-ink-muted">{{ $offer->description }}</p>
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
