{{-- Дорожки редактора (06.10.2026, владелец: «как три параллельных маршрута»): одно событие — одно место.
     «Продажа» — вся коммерческая жизнь: маршрут со страховой, у гаражной машины дальше её продажа и расчёт.
     «Вывоз» — задача логистики: где машина физически, кто вывозит (у сделки — и кто забирает у владельца, с контактом).
     Третья — «Гараж» (подготовка, расходы), «Сделка» (кто взял, резерв), «Продана на аукционе» (кто купил, отмена, деньги)
     или «Подтверждения»; у гаража и сделки она
     одинаково кончается деньгами и ДКП (`admin.offers.deal-cards`; заметка и «Показ» — справа под «Ценами», 12.10.2026, 07.10.2026: «гараж — это тоже как сделка»).
     На ПК в ряд в основной колонке (колонок столько, сколько дорожек: две от 34rem, три от 52rem ширины колонки —
     `@container/lanes` в редакторе), на телефоне — столбиком в том же порядке.
     compact — окно машины (12.11.2026, владелец: «информация по несколько раз дублируется, закупочная несколько раз, менеджер
     несколько раз»): кто держит машину и на каких условиях — в сведениях шапки (`admin.car.facts`), здесь не повторяется;
     путь — текущим шагом, остальное свёрнуто; вывоз — без лесенки и истории; деньги — без числа шапки; ДКП и ссылка — нет. --}}
@php
    use App\Offers\OfferState;
    use App\Workflow\Track;
    $compact ??= false;
    // Какие дорожки — одно решение с окном (`CarView::lanes`); здесь только раскладка.
    $g = $garageView;
    ['sale' => $saleLane, 'pickup' => $pickupLane, 'third' => $third] = $car->lanes();
    $salePos = $offer->position(Track::Sale);
    // Хвост пути продажи у гаражной машины: в продаже, продана, расчёт — с кнопками на текущем шаге.
    $tail = $g ? view('garage.cars.path', $g + ['set' => 'sale', 'bare' => true, 'buttonsHere' => $g['sale']])->render() : '';
    // Идущая сделка без вывоза — получение (контакт владельца, кто забирает) своим блоком под «Вывозом»: с вывозом оно
    // внутри него (`x-logistics.lane`). До 11.11.2026 в редакторе без вывоза получения не было вовсе, в окне сделки — было.
    $handover = $deal?->isActive() && ! $deal->isGarage() && ! $offer->pickup();
    // Окно: у гаражной без маршрута продажа — будущее, пока машину везут и готовят (три серых шага без текущего), — не рисуем.
    if ($compact && ! $salePos && $garageCar && ! in_array($garageCar->state, \App\Garage\CarState::sale(), true)) {
        $saleLane = false;
    }
    $count = (int) $saleLane + (int) ($pickupLane || $handover) + (int) ($third !== null);
@endphp
@if ($count)
    <div @class(['grid grid-cols-1 items-start gap-4', '@[34rem]/lanes:grid-cols-2' => $count > 1, '@[52rem]/lanes:grid-cols-3' => $count > 2])>
        @if ($saleLane)
            <div class="flex min-w-0 flex-col gap-4">
                @if ($salePos)
                    @include('admin.offers.route', ['only' => Track::Sale, 'cardClass' => '', 'tail' => $tail, 'compact' => $compact])
                @else
                    <x-ui.card title="Продажа" id="sale"><div @class(['steps', 'steps--compact' => $compact])>{!! $tail !!}@if ($compact)<x-route.fold/>@endif</div></x-ui.card>
                @endif
                @if ($garageCar?->isSold())
                    <x-ui.card title="Расчёт">
                        @if ($g['moreSale'])
                            <x-slot:actions>@include('garage.cars.actions', ['buttons' => [], 'more' => $g['moreSale'], 'bar' => false, 'menuKey' => '-sale'] + $g)</x-slot:actions>
                        @endif
                        @include('garage.cars.money', $g)
                    </x-ui.card>
                @endif
            </div>
        @endif

        @if ($pickupLane || $handover)
            <div class="flex min-w-0 flex-col gap-4">
                @if ($pickupLane)<x-logistics.lane :offer="$offer" :deal="$deal?->isActive() ? $deal : null" :compact="$compact"/>@endif
                @if ($handover)<x-deal.handover :deal="$deal" compact/>@endif
            </div>
        @endif

        @if ($third === 'garage')
            <div class="flex min-w-0 flex-col gap-4">
                @include('admin.offers.garage-card', ['compact' => $compact])
                @if ($garageCar->deal)@include('admin.offers.deal-cards', ['deal' => $garageCar->deal, 'compact' => $compact])@endif
                {{-- Резерв гаражной сделки: передумал — «Отдать» другому подтвердившему. Интерес из галереи — рядом. --}}
                @if ($offer->deal && $bids->where('state', \App\Offers\BidState::Active)->isNotEmpty())
                    <x-ui.card title="Подтверждения">@include('admin.offers.bids', ['inside' => true, 'compact' => $compact])</x-ui.card>
                @endif
                @if ($offer->interests->isNotEmpty())
                    @include('admin.offers.interests')
                @endif
            </div>
        @elseif ($third === 'auction')
            <div class="flex min-w-0 flex-col gap-4">
                @include('admin.offers.auction-card')
                <x-deal.money :deal="$deal" id="money"/>
            </div>
        @elseif ($third === 'deal')
            <div class="flex min-w-0 flex-col gap-4">
                {{-- В окне принятое подтверждение не повторяется — его цена справа в шапке, менеджер в сведениях: только резерв. --}}
                @php ob_start(); @endphp
                @include('admin.offers.bids', ['inside' => true, 'compact' => $compact])
                @php $dealBids = trim(ob_get_clean()); @endphp
                @if (! $compact || $offer->bids->where('state', \App\Offers\BidState::Active)->isNotEmpty() || $offer->bids->count() > 1)
                    <x-ui.card :title="$compact ? 'Подтверждения' : 'Сделка'" id="deal" class="scroll-mt-24">{!! $dealBids !!}</x-ui.card>
                @endif
                {{-- Интерес из галереи — рядом с подтверждениями: опубликовали раньше срока, отклики остались. --}}
                @if ($offer->interests->isNotEmpty())
                    @include('admin.offers.interests')
                @endif
                @include('admin.offers.deal-cards', ['compact' => $compact])
            </div>
        @elseif ($third === 'bids')
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
