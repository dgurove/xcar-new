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
        {{-- Черновик на оценке (11.10.2026) в избранное не кладётся: в списке «Избранного» его не будет. --}}
        @if ($user && ($offer->isGallery() || $offer->state !== \App\Offers\OfferState::Draft))<x-offer.favorite :offer="$offer" variant="compact"/>@endif
        {{-- Черновик на оценке — задача, а не страница списка: листать соседей не из чего. --}}
        @unless ($offer->isAskedOf($user))
        <x-ui.nav-arrows class="ml-auto sm:ml-0"
            :prev="$position['prev'] ? $context->offerUrl($position['prev']) : null"
            :next="$position['next'] ? $context->offerUrl($position['next']) : null"
            :back="$back" :index="$position['index']" :total="$position['total']"/>
        @endunless
    </x-slot:actions>

    <div class="-mt-3 mb-6 flex flex-wrap gap-1.5">@if ($offer->recommended)<x-offer.recommended label/>@endif<x-offer.tags :offer="$offer" :facts="false"/></div>

    {{-- Слева галерея и описание, справа цена и характеристики: всё о машине — в первом экране. --}}
    <div class="grid gap-6 lg:grid-cols-[1fr_22rem] lg:gap-8">
        <div class="lg:col-start-1 lg:row-start-1">
            {{-- Знак на кадрах поставила оценка — уйдёт вместе с ней (владелец 11.10.2026). Запрет шеринга админа — строки нет. --}}
            @if ($offer->share_locked_by_estimate && $photos->isNotEmpty() && \App\Offers\Estimate::openFor($offer, $user))<p class="mb-2 text-sm text-ink-muted">Водяные знаки пропадут после оценки</p>@endif
            <x-offer.gallery :photos="$photos" :alt="$offer->titleWithYear()"/>
        </div>

        <aside class="flex flex-col gap-6 lg:col-start-2 lg:row-span-2 lg:row-start-1 lg:gap-8">
            <x-offer.deal-box :offer="$offer" :my-bid="$myBid" :my-interest="$myInterest" :chat="$chat" :can-chat="$canChat"/>

            @if ($user?->isManager() && $offer->state->isPublic())
                {{-- Менеджер: кто из его покупателей это видит. Видящие всё (12.10.2026, по умолчанию) — одной строкой со
                     ссылкой в «Покупатели»; «Выбранным» — чипы открытого (чип — и есть кнопка «закрыть», с подтверждением) и
                     «Открыть…»; покупателей нет — «Пригласить». Ниже — кто проявил интерес. На телефоне — после цены. --}}
                @php
                    $seeAll = \App\Offers\Showing::seeAllCount($user);
                    $pickable = \App\Offers\Showing::pickable($user);
                @endphp
                <section class="box order-3 lg:order-2" data-controller="sheet" data-action="show:open@window->sheet#open">
                    <h2 class="box-title">Покупатели</h2>
                    @if ($seeAll || $showings->isNotEmpty() || $pickable)
                        @if ($seeAll)
                            <a href="/buyers" class="mt-3 flex items-center gap-2 text-ink-muted">
                                <x-ui.icon name="eye" class="size-5 shrink-0"/>
                                <span class="min-w-0 flex-1">{{ \App\Support\Plural::of($seeAll, ['Видит', 'Видят', 'Видят']) }} <span class="nums">{{ $seeAll }}</span> {{ \App\Support\Plural::of($seeAll, ['покупатель', 'покупателя', 'покупателей']) }}</span>
                                <x-ui.chevron/>
                            </a>
                        @endif
                        @if ($showings->isNotEmpty() || $pickable)
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
                                @if ($pickable && $offer->state === \App\Offers\OfferState::Open)
                                    <button type="button" class="chip rounded-full !py-0.5 text-accent-text" data-action="sheet#open"><x-ui.icon name="plus" class="size-4"/> Открыть…</button>
                                @endif
                            </div>
                        @endif
                    @else
                        <a href="/buyers/invites" class="btn btn-quiet mt-4 w-full"><x-ui.icon name="link" class="size-5"/> Пригласить покупателей</a>
                    @endif
                    @if ($buyerInterests->isNotEmpty())
                        <div class="list mt-4">
                            @foreach ($buyerInterests as $interest)
                                @include('cabinet.buyers.interest-row', ['interest' => $interest, 'person' => true, 'car' => false])
                            @endforeach
                        </div>
                    @endif
                    @if ($pickable)
                        <x-ui.sheet id="show" title="Открыть покупателям" wide>
                            <turbo-frame id="show-frame" src="/buyers/showings/new?offers[]={{ $offer->id }}&back={{ urlencode(request()->getRequestUri()) }}" loading="lazy" target="_top" class="block min-h-40">
                                <x-ui.skeleton :rows="3"/>
                            </turbo-frame>
                        </x-ui.sheet>
                    @endif
                </section>
            @endif

            @if ($dealInSheet && $price->shown())
                {{-- Телефон: цена и под ней срок приёма — тем, кто подтверждает (11.10.2026: на плитке «осталось 3 д», а на
                     странице срок был только в шторке «Подтвердить»). --}}
                <div class="order-1 lg:hidden">
                    <div class="nums text-2xl leading-none" data-controller="fit">@if ($price->withFrom())<span class="text-[.7em] text-ink-muted">{{ $price::money($price->from) }}&nbsp;→</span> @endif{{ $price::money($price->to) }}&nbsp;₽@if ($price->vat) <span class="text-[.55em] font-normal text-ink-muted">с НДС</span>@endif</div>
                    <x-offer.when :offer="$offer" class="mt-2"/>
                </div>
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
