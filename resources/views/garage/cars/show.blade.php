{{-- Машина в гараже — полная страница ТС (`x-offer.object`): кадры листаются и открываются во весь экран, характеристики,
     документы, открытые менеджеру. Своё у гаража: состояние под заголовком; ждёт страховую — шаг сделки с её кнопками
     («Забираю», «Отказываюсь»), как на странице сделки; путь, деньги, расходы; внизу кнопка того, что сейчас надо сделать
     (`GarageView`), редкое — в «⋯». Шторки: расход, «Продаю», «Продана», счёт с вознаграждением, «Поступило», «Оплатить». --}}
@php extract($view); @endphp
<x-ui.shell :title="$offer->titleWithYear()" :back="['Гараж', '/garage']">
    <div class="-mt-3 mb-5 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-sm">
        <x-ui.state :tone="$car->state->tone()" class="text-sm">{{ mb_strtolower($car->state->label()) }}</x-ui.state>
        @if ($staff)<span class="text-ink-muted">{{ $car->manager?->shortName() ?? 'взяли под себя' }}</span>@endif
        @if ($staff && $car->deal_id && $car->isWaiting())<a href="{{ \App\Support\Surface::Crm->url('/work/deals/'.$car->deal_id) }}" class="text-accent-text" data-turbo="false">Сделка в CRM</a>@endif
    </div>
    @error('car')<x-ui.flash tone="danger" class="mb-4">{{ $message }}</x-ui.flash>@enderror

    <x-offer.object :offer="$offer" :photos="$photos" :docs="$docs">
        @php $chat = ! $staff && $offer->chatOpenFor($user); @endphp
        @if ($money || $chat)
            <x-slot:aside>
                @if ($money)@include('garage.cars.money')@endif
                {{-- Вопросы по машине — тем же чатом, что и до гаража: на всех этапах до продажи. --}}
                @if ($chat)<a href="/account/chats/offer/{{ $offer->number }}" class="btn btn-quiet w-full"><x-ui.icon name="chat" class="size-5"/> Написать</a>@endif
            </x-slot:aside>
        @endif

        @if ($step && ! $staff)
            @include('cabinet.deals.step', $step + ['ladder' => false])
        @endif

        <div class="box">
            <h2 class="box-title">Путь</h2>
            <div class="mt-3">@include('garage.cars.path')</div>
        </div>

        @include('garage.cars.costs')
    </x-offer.object>

    @if ($buttons || $more)
        <x-ui.action-bar>@include('garage.cars.actions', ['bar' => true])</x-ui.action-bar>
    @endif

    @include('garage.cars.sheets')
</x-ui.shell>
