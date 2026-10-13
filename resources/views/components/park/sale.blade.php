{{-- Продажа этой ТС — только админу (владелец 05.10.2026: «выставить и убрать на парковке, красиво и удобно»).
     Вид — как документы в том же месте (11.10.2026, владелец: кнопку не находили, «подумай над дизайном»):
     - row — строкой группы в карточке «Наличия» и «Заявок», как «Заполнить из документов»: не в продаже — «Выставить в
       продажу» лаймовым словом с подтверждением; в продаже — «Продажа» и состояние справа, нажатие — шторка;
     - иначе капсулой в шапке дела рядом с местом («Измайловский лес ⌄»): «В продажу» с сумкой / состояние ⌄.
     Шторка «Продажа» — предложение в CRM, сделка, если идёт, и красная «Убрать из продажи» (`TakeOffSale`: черновик
     удаляется, опубликованное — в архив; ТС с фото и документами остаётся). Сделку, гараж и проданную так не снять.
     Лаймовой заливки нет: главное действие карточки — «Принять» / «Выдать» подвалом. --}}
@props(['vehicle', 'row' => false])
@php
    use App\Offers\OfferState;
    use App\Support\Surface;
    $offer = $vehicle->offer;
    $id = 'sale-'.$vehicle->id;
    // Страховая уже продала или ТС ждёт выдачи — выставлять нечего (владелец 09.10.2026: «зачем выставлять в продажу,
    // если её уже выдавать надо»).
    $canSell = ! $offer && ! ($vehicle->state->isFinal() || $vehicle->sold_at || $vehicle->openRequest(\App\Park\RequestType::Release));
    $canUnsell = $offer && \App\Park\Actions\TakeOffSale::allowed($offer->state, (bool) $offer->deal);
    if ($offer) {
        $word = match ($offer->state) {
            OfferState::Draft => $offer->published_at ? 'Снята с сайта' : 'Готовится к продаже',
            OfferState::Gallery => 'Скоро в продаже',
            OfferState::Open => 'В продаже',
            OfferState::Sold => 'Идёт сделка',
            OfferState::Garage => 'В гараже',
            OfferState::Delivered => 'Продана',
            default => $offer->state->label(),
        };
        $tone = match ($offer->state) { OfferState::Open => 'open', OfferState::Sold, OfferState::Garage => 'urgent', default => 'plain' };
        $price = $offer->asking_price ? \App\Support\Money::rub($offer->asking_price) : null;
    }
@endphp
@if (! auth()->user()?->isAdmin() || (! $offer && ! $canSell))
@elseif (! $offer)
    <form method="post" action="/cars/{{ $vehicle->id }}/sell" @class(['list mt-3' => $row, 'contents' => ! $row]) data-turbo-frame="_top" data-turbo-confirm="Выставить в продажу?" data-turbo-confirm-text="{{ $vehicle->saleLabel() }}" data-turbo-confirm-label="Выставить">@csrf
        @if ($row)
            <button class="row w-full text-left"><x-ui.row-icon name="bag" tone="soft" size="s"/><span class="min-w-0 flex-1 text-accent-text">Выставить в продажу</span><x-ui.chevron/></button>
        @else
            <button class="pill pill-soft gap-1.5"><x-ui.icon name="bag" class="size-4"/>В продажу</button>
        @endif
    </form>
@else
    <span @class(['list mt-3' => $row, 'contents' => ! $row]) data-controller="sheet">
        @if ($row)
            <button type="button" class="row w-full text-left" data-action="sheet#open">
                <x-ui.row-icon name="bag" :tone="$tone === 'plain' ? 'muted' : $tone" size="s"/>
                <span class="min-w-0 flex-1">Продажа</span>
                <span class="shrink-0 text-right"><span @class(['block', 'text-open' => $tone === 'open', 'text-urgent' => $tone === 'urgent', 'text-ink-muted' => $tone === 'plain'])>{{ mb_strtolower($word) }}</span>@if ($price)<span class="block nums text-sm text-ink-muted">{{ $price }}</span>@endif</span>
                <x-ui.chevron/>
            </button>
        @else
            <button type="button" class="pill pill-{{ $tone }} gap-1" data-action="sheet#open">{{ $word }}<x-ui.icon name="chevron-down" class="size-3.5"/></button>
        @endif
        <x-ui.sheet :id="$id" title="Продажа">
            <div class="list">
                <a href="{{ Surface::Crm->url('/offers/'.$offer->number) }}" class="row" data-turbo="false">
                    <span class="min-w-0 flex-1">Предложение № {{ $offer->number }}<span class="row-sub">{{ $offer->crmTab() }}@if ($price), {{ $price }}@endif</span></span>
                    <x-ui.chevron/>
                </a>
                @if ($offer->deal)
                    <a href="{{ Surface::Crm->url('/work/deals/'.$offer->deal->id) }}" class="row" data-turbo="false">
                        <span class="min-w-0 flex-1">Сделка<span class="row-sub">снять с продажи можно, только отменив её в CRM</span></span>
                        <x-ui.chevron/>
                    </a>
                @endif
            </div>
            @if ($canUnsell)
                <form method="post" action="/cars/{{ $vehicle->id }}/unsell" class="mt-4" data-turbo-frame="_top" data-turbo-confirm="Убрать из продажи?" data-turbo-confirm-text="ТС с фото и документами остаётся на парковке">
                    @csrf<button class="btn btn-danger w-full">Убрать из продажи</button>
                </form>
            @endif
        </x-ui.sheet>
    </span>
@endif
