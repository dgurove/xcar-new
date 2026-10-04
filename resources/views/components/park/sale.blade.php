{{-- Продажа этой ТС — только админу (владелец 05.10.2026: «выставить и убрать на парковке, красиво и удобно»).
     Не в продаже — лаймовая «Выставить в продажу» с подтверждением (машина, госномер, убыток). В продаже — капсула
     состоянием продажи со стрелкой: шторка «Продажа» — предложение в CRM строкой, сделка, если идёт, и красная «Убрать из
     продажи» (`TakeOffSale`: черновик удаляется, опубликованное — в архив; ТС с фото и документами остаётся). Сделку,
     гараж и проданную так не снять — кнопки нет. --}}
@props(['vehicle'])
@php
    use App\Offers\OfferState;
    use App\Support\Surface;
    $offer = $vehicle->offer;
    $id = 'sale-'.$vehicle->id;
@endphp
@if (auth()->user()?->isAdmin())
    @if (! $offer)
        @unless ($vehicle->state->isFinal())
            <form method="post" action="/cars/{{ $vehicle->id }}/sell" class="contents" data-turbo-frame="_top" data-turbo-confirm="Выставить в продажу?" data-turbo-confirm-text="{{ $vehicle->saleLabel() }}">@csrf<button class="btn btn-s btn-accent">Выставить в продажу</button></form>
        @endunless
    @else
        @php
            $deal = $offer->deal;
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
        @endphp
        <span class="contents" data-controller="sheet">
            <button type="button" class="pill pill-{{ $tone }} gap-1" data-action="sheet#open">{{ $word }}<x-ui.icon name="chevron-down" class="size-3.5"/></button>
            <x-ui.sheet :id="$id" title="Продажа">
                <div class="list">
                    <a href="{{ Surface::Crm->url('/offers/'.$offer->number) }}" class="row" data-turbo="false">
                        <span class="min-w-0 flex-1">Предложение № {{ $offer->number }}<span class="row-sub">{{ mb_strtolower($offer->state->labelFor(auth()->user())) }}@if ($price), {{ $price }}@endif</span></span>
                        <x-ui.chevron/>
                    </a>
                    @if ($deal)
                        <a href="{{ Surface::Crm->url('/work/deals/'.$deal->id) }}" class="row" data-turbo="false">
                            <span class="min-w-0 flex-1">Сделка<span class="row-sub">снять с продажи — только отменив её в CRM</span></span>
                            <x-ui.chevron/>
                        </a>
                    @endif
                </div>
                @if (\App\Park\Actions\TakeOffSale::allowed($offer->state, (bool) $deal))
                    <form method="post" action="/cars/{{ $vehicle->id }}/unsell" class="mt-4" data-turbo-frame="_top" data-turbo-confirm="Убрать из продажи?" data-turbo-confirm-text="ТС с фото и документами остаётся на парковке">
                        @csrf<button class="btn btn-danger w-full">Убрать из продажи</button>
                    </form>
                @endif
            </x-ui.sheet>
        </span>
    @endif
@endif
