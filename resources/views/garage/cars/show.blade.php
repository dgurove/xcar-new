{{-- Машина в гараже — полная страница ТС (`x-offer.object`): кадры листаются и открываются во весь экран, характеристики,
     документы, открытые менеджеру. Своё у гаража: состояние под заголовком; пока идёт сделка со страховой — её шаг с
     кнопками («Забираю», «Отказываюсь»), как на странице сделки; путь одной лесенкой (сделка, где машина, подготовка,
     продажа — у сотрудника в CRM это три дорожки); деньги, расходы; внизу кнопка того, что сейчас надо сделать
     (`GarageView`: «Забрал», если везёт сам, «Готова», «Продаю»), редкое — в «⋯». Шторки: расход, «Продаю», «Продана»,
     счёт с вознаграждением, «Поступило», «Оплатить». --}}
@php extract($view); @endphp
<x-ui.shell :title="$offer->titleWithYear()" copy-title :back="['Гараж', '/garage']">
    {{-- Состояние — мелким цветным словом у названия (`.title-state`, как у карточек), чья и «Сделка в CRM» — строкой
         справа; на ПК слово больше не висит в углу шапки оторванно от названия (10.10.2026). --}}
    {{-- «Ждёт ТС» у названия не пишем (15.11.2026, владелец: «что это за надпись, зачем она»): что с вывозом — путь ниже. --}}
    <x-slot:badge>
        <x-car.keys :count="$car->offer->keys_count" label/>
        @unless ($car->isWaiting())
            <x-ui.state :tone="$car->state->tone()" class="title-state">{{ mb_lcfirst($car->state->label()) }}</x-ui.state>
        @endunless
    </x-slot:badge>
    @if ($staff)
        <x-slot:actions>
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1.5 text-sm">
            <span class="text-ink-muted">{{ $car->manager?->shortName() ?? 'взяли под себя' }}</span>
            @if ($car->dealOpen())<a href="{{ \App\Support\Surface::Crm->url('/work/deals/'.$car->deal_id) }}" class="text-accent-text" data-turbo="false">Сделка в CRM</a>@endif
        </div>
        </x-slot:actions>
    @endif
    @error('car')<x-ui.flash tone="danger" class="mb-4">{{ $message }}</x-ui.flash>@enderror

    <x-offer.object :offer="$offer" :photos="$photos" :docs="$docs">
        @php $chat = ! $staff && $offer->chatOpenFor($user); @endphp
        <x-slot:aside>
            @if ($money)@include($asBuyer ? 'garage.cars.money-buyer' : 'garage.cars.money')@endif
            {{-- Вопросы по машине — тем же чатом, что и до гаража: на всех этапах до продажи. --}}
            @if ($chat)<a href="{{ \App\Chats\Chat::offerPath($offer->number) }}" class="btn btn-quiet btn-bleed w-full"><x-ui.icon name="chat" class="size-5"/> Написать</a>@endif
            <x-offer.my-note :offer="$offer"/>
        </x-slot:aside>

        @if ($step && ! $staff)
            <x-offer.reprice-ask :deal="$step['deal']"/>
            {{-- Доп. просьба поставщику держит сделку (18.11.2026, `Offers\ExtraAsk`) — её задача вместо шага, как на странице сделки. --}}
            @if ($extraAsk = \App\Offers\ExtraAsk::holding($step['deal']))
                @include('cabinet.deals.extra-ask', $step + ['ask' => $extraAsk])
            @else
                @include('cabinet.deals.step', $step)
            @endif
            {{-- Деньги и документы покупки — тем же блоком, что на странице сделки (18.11.2026, владелец: «к единой схеме»):
                 покупатель → счёт → договор, платёжка, наличные. --}}
            {{-- И покупателю с гаражом — тот же блок (26.11.2026, владелец про Ананьева: «как полноценный менеджер ведёт сделку»):
                 покупатель с «+ Новый покупатель» и паспортом, договор, подписанный, документ об оплате. Закупочной в нём нет:
                 цена договора и счёта — его цена (`DealContract::priceFor`). --}}
            @include('cabinet.deals.receipt-card', $step)
            @include('cabinet.deals.buyer-draft', ['deal' => $step['deal']])
        @else
            {{-- После покупки его дело — той же шапкой, что у сделки (18.11.2026): забрать, указать покупателя, оплатить счёт. --}}
            @if ($garageTurn = \App\Offers\Turn::ofGarage($car, $user, $view))
                <div class="box"><x-deal.turn-head :turn="$garageTurn"/></div>
            @endif
            @if (! $staff && $car->deal && ($parkHandover = \App\Offers\Handover::for($car->deal))->park && $car->deal->passes()->whereNull('revoked_at')->exists())
            {{-- Сделка со страховой закрыта, а машина ещё на нашей парковке — забрать по пропуску (18.10.2026). --}}
                <div class="box"><h2>Забрать с парковки</h2>@include('cabinet.deals.park-pickup', ['deal' => $car->deal, 'handover' => $parkHandover])</div>
            @endif
        @endif

        <div class="box">
            <h2 class="box-title">Путь</h2>
            <div class="mt-3">@include('garage.cars.path', ['set' => 'all'])</div>
        </div>

        @include('garage.cars.costs')
    </x-offer.object>

    @if ($buttons || $more)
        <x-ui.action-bar>@include('garage.cars.actions', ['bar' => true])</x-ui.action-bar>
    @endif

    @include('garage.cars.sheets')
</x-ui.shell>
