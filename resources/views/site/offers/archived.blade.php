{{-- Предложение из архива менеджера (`Offer::isArchivedFor`, 23.11.2026, начальник: «зайти и написать админам по ней»; «почему
     она туда попала, видеть не должны — для них она просто архивная»). Тот же адрес `/offers/N`, свой вид: «в архиве» у названия,
     фото, цена как была на витрине, характеристики, описание; действие одно — «Написать» (чат по ТС с площадкой). Ни срока приёма,
     ни подтверждения, ни «Покупателей», ни избранного, ни «Поделиться», ни стрелок к соседям. --}}
@php
    $price = \App\Offers\PriceView::for($offer, auth()->user());
    $chatHref = \App\Chats\Chat::offerPath($offer->number);
    $unread = $chat?->unread_for_user;
    // Чат у человека выключен — «Написать» не рисуется: ссылка вела бы в 404.
    $canChat = $offer->chatOpenFor(auth()->user());
@endphp
<x-ui.shell :title="$offer->titleWithYear()" copy-title :back="['Архив', '/offers/archive']" :trail="[['Главная', '/'], ['Архив', '/offers/archive'], ['№ '.$offer->number]]">
    <x-slot:badge><span class="title-state text-ink-muted">в архиве</span></x-slot:badge>

    <div class="grid gap-4 lg:grid-cols-[1fr_22rem] lg:gap-6">
        <div class="lg:col-start-1 lg:row-start-1">
            <x-offer.gallery :photos="$photos" :alt="$offer->titleWithYear()" :offer="$offer"/>
        </div>

        <aside class="flex flex-col gap-4 lg:col-start-2 lg:row-span-2 lg:row-start-1 lg:gap-6">
            @if ($price->shown())
                <section class="box">
                    <div class="nums text-[32px] leading-none" data-controller="fit">@if ($price->withFrom())<span class="text-[.7em] text-ink-muted">{{ $price::money($price->from) }}&nbsp;→</span> @endif{{ $price::money($price->to) }}&nbsp;₽@if ($price->vat) <span class="text-[.55em] font-normal text-ink-muted">с НДС</span>@endif</div>
                    @if ($canChat)<a href="{{ $chatHref }}" class="btn btn-accent relative mt-4 w-full max-lg:hidden" data-controller="emit" data-action="emit#send" data-emit-event-param="chat:open" data-emit-wide-param="1024">Написать@if ($unread)<span class="badge nums ml-1">{{ $unread }}</span>@endif</a>@endif
                </section>
            @elseif ($canChat)
                <a href="{{ $chatHref }}" class="btn btn-accent relative w-full max-lg:hidden" data-controller="emit" data-action="emit#send" data-emit-event-param="chat:open" data-emit-wide-param="1024">Написать@if ($unread)<span class="badge nums ml-1">{{ $unread }}</span>@endif</a>
            @endif
            <x-offer.facts :offer="$offer"/>
        </aside>

        @if ($offer->publicDescription())
            <section class="lg:col-start-1 lg:row-start-2">
                <h2 class="list-head">Описание</h2>
                <p class="whitespace-pre-line text-ink-muted">{{ $offer->publicDescription() }}</p>
            </section>
        @endif
    </div>

    @if ($canChat)
    <div data-controller="sheet" data-action="chat:open@window->sheet#open" class="contents">
        <x-ui.sheet id="chat" :title="\App\Chats\Chat::PLATFORM" :open="request()->boolean('chat')" wide>
            <turbo-frame id="offer-chat" src="/offers/{{ $offer->number }}/chat" loading="{{ request()->boolean('chat') ? 'eager' : 'lazy' }}" target="_top" class="block min-h-32">
                <x-ui.skeleton :rows="2"/>
            </turbo-frame>
        </x-ui.sheet>
    </div>

    <x-ui.action-bar class="lg:hidden">
        <a href="{{ $chatHref }}" class="btn btn-accent relative min-w-0 flex-1" data-controller="emit" data-action="emit#send" data-emit-event-param="chat:open" data-emit-wide-param="1024">Написать@if ($unread)<span class="badge nums ml-1">{{ $unread }}</span>@endif</a>
    </x-ui.action-bar>
    @endif
</x-ui.shell>
