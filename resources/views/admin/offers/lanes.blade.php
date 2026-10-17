{{-- Дорожки редактора (06.10.2026, владелец: «как три параллельных маршрута»): одно событие — одно место.
     «Продажа» — вся коммерческая жизнь: маршрут со страховой, у гаражной машины дальше её продажа и расчёт.
     «Вывоз» — задача логистики: где машина физически, кто вывозит (у сделки — и кто забирает у владельца, с контактом).
     Третья — «Гараж» (подготовка, расходы), «Сделка» (кто взял, резерв) или «Подтверждения»; у гаража и сделки она
     одинаково кончается деньгами и ДКП (`admin.offers.deal-cards`; заметка и «Показ» — справа под «Ценами», 12.10.2026, 07.10.2026: «гараж — это тоже как сделка»).
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
        // Черновик в галерее: интерес и «+ Интерес» за менеджера (13.10.2026).
        $offer->isGallery() || $offer->interests->isNotEmpty() => 'interest',
        default => null,
    };
    // Вывоз — задача логистики (17.10.2026): идёт или был, или его заводят «Вывезти» — у гаражной, у сделки и у вендора,
    // чьи машины вывозим всегда (Совкомбанк).
    $pickupLane = $offer->pickup() || (! in_array($offer->state, [OfferState::Delivered, OfferState::Cancelled, OfferState::Archived], true)
        && ($g || $deal || $offer->vendor?->pickup_on_arrival));
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
                <x-logistics.lane :offer="$offer" :deal="$deal?->isActive() ? $deal : null"/>
            </div>
        @endif

        @if ($third === 'garage')
            <div class="flex min-w-0 flex-col gap-4">
                <x-ui.card title="Гараж" id="garage" class="scroll-mt-24">@include('admin.offers.garage-card')</x-ui.card>
                @if ($car->deal)@include('admin.offers.deal-cards', ['deal' => $car->deal])@endif
                {{-- Резерв гаражной сделки: передумал — «Отдать» другому подтвердившему. Интерес из галереи — рядом. --}}
                @if ($offer->deal && $bids->where('state', \App\Offers\BidState::Active)->isNotEmpty())
                    <x-ui.card title="Подтверждения">@include('admin.offers.bids', ['inside' => true])</x-ui.card>
                @endif
                @if ($offer->interests->isNotEmpty())
                    @include('admin.offers.interests')
                @endif
            </div>
        @elseif ($third === 'deal')
            <div class="flex min-w-0 flex-col gap-4">
                <x-ui.card title="Сделка" id="deal" class="scroll-mt-24">@include('admin.offers.bids', ['inside' => true])</x-ui.card>
                {{-- Интерес из галереи — рядом с подтверждениями: опубликовали раньше срока, отклики остались. --}}
                @if ($offer->interests->isNotEmpty())
                    @include('admin.offers.interests')
                @endif
                @include('admin.offers.deal-cards')
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
        @elseif ($third === 'interest')
            <div class="flex min-w-0 flex-col gap-4">@include('admin.offers.interests')</div>
        @endif
    </div>
@endif
