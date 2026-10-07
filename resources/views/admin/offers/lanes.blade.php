{{-- Дорожки редактора (06.10.2026, владелец: «как три параллельных маршрута»): одно событие — одно место.
     «Продажа» — вся коммерческая жизнь: маршрут со страховой, у гаражной машины дальше её продажа и расчёт.
     «Вывоз» — только где машина физически (у сделки — и кто забирает у владельца, с контактом).
     Третья — «Гараж» (подготовка, расходы), «Сделка» (кто взял, деньги, ДКП, резерв, заметка) или «Подтверждения».
     На ПК в ряд в основной колонке (колонок столько, сколько дорожек: две от 34rem, три от 52rem ширины колонки —
     `@container/lanes` в редакторе), на телефоне — столбиком в том же порядке. --}}
@php
    use App\Offers\OfferState;
    use App\Workflow\Track;
    $g = $garageView;
    $salePos = $offer->position(Track::Sale);
    $third = match (true) {
        (bool) $g => 'garage',
        (bool) $deal => 'deal',
        $bids->isNotEmpty() || $offer->state === OfferState::Open => 'bids',
        default => null,
    };
    // Вывоз: идёт, или его можно назначить (у гаражной — всегда).
    $service = $offer->vendor?->workflow(Track::Service);
    $pickupLane = $offer->position(Track::Service) || $g || ($service?->is_active && ! in_array($offer->state, [OfferState::Delivered, OfferState::Cancelled, OfferState::Archived], true));
    $saleLane = $salePos || $g;
    $count = (int) $saleLane + (int) $pickupLane + (int) ($third !== null);
    // Хвост пути продажи у гаражной машины: в продаже, продана, расчёт — с кнопками на текущем шаге.
    $tail = $g ? view('garage.cars.path', $g + ['set' => 'sale', 'bare' => true, 'buttonsHere' => $g['sale']])->render() : '';
    $car = $g['car'] ?? null;
@endphp
@if ($count)
    <div @class(['grid grid-cols-1 items-start gap-4', '@[34rem]/lanes:grid-cols-2' => $count > 1, '@[52rem]/lanes:grid-cols-3' => $count > 2])>
        @if ($saleLane)
            <div class="flex min-w-0 flex-col gap-4">
                @if ($salePos)
                    @include('admin.offers.route', ['only' => Track::Sale, 'cardClass' => '', 'tail' => $tail])
                @else
                    <x-ui.card title="Продажа" id="sale"><div class="steps">{!! $tail !!}</div></x-ui.card>
                @endif
                {{-- Гаражная «платит менеджер»: наша доля и машина на закупочную — деньги сделки со страховой. --}}
                @if ($deal?->isGarage() && $deal->garage_payer === \App\Garage\GaragePayer::Manager)
                    <x-deal.money :deal="$deal" id="money"/>
                @endif
                @if ($car?->isSold())
                    <x-ui.card title="Расчёт">
                        @if ($g['moreSale'])
                            <x-slot:actions>@include('garage.cars.actions', ['buttons' => [], 'more' => $g['moreSale'], 'bar' => false, 'menuKey' => '-sale'] + $g)</x-slot:actions>
                        @endif
                        @include('garage.cars.money', $g)
                    </x-ui.card>
                @endif
            </div>
        @endif

        @if ($pickupLane)
            <div class="flex min-w-0 flex-col gap-4">
                @include('admin.offers.route', ['only' => Track::Service, 'cardClass' => '', 'deal' => $deal?->isActive() ? $deal : null])
            </div>
        @endif

        @if ($third === 'garage')
            <div class="flex min-w-0 flex-col gap-4">
                <x-ui.card title="Гараж" id="garage" class="scroll-mt-24">@include('admin.offers.garage-card')</x-ui.card>
                {{-- Резерв гаражной сделки: передумал — «Отдать» другому подтвердившему. --}}
                @if ($offer->deal && $bids->where('state', \App\Offers\BidState::Active)->isNotEmpty())
                    <x-ui.card title="Подтверждения">@include('admin.offers.bids', ['inside' => true])</x-ui.card>
                @endif
            </div>
        @elseif ($third === 'deal')
            <div class="flex min-w-0 flex-col gap-4">
                <x-ui.card title="Сделка" id="deal" class="scroll-mt-24">@include('admin.offers.bids', ['inside' => true])</x-ui.card>
                <x-deal.money :deal="$deal" id="money"/>
                @if ($deal->hasContract())<x-deal.contract :deal="$deal"/>@endif
                <x-deal.note :deal="$deal" id="deal-note"/>
            </div>
        @elseif ($third === 'bids')
            @php $waiting = $bids->where('state', \App\Offers\BidState::Active); @endphp
            <div class="flex min-w-0 flex-col gap-4">
                <x-ui.card :title="'Подтверждения'.($waiting->isNotEmpty() ? ' '.$waiting->count() : '')" :class="$waiting->isNotEmpty() ? 'box-urgent' : ''">
                    @include('admin.offers.bids', ['inside' => true])
                </x-ui.card>
                @if ($offer->interests->isNotEmpty())
                    @include('admin.offers.interests')
                @endif
            </div>
        @endif
    </div>
@endif
