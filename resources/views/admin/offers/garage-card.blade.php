{{-- Дорожка «Гараж» окна машины и страницы (06.10.2026: только подготовка) из `GarageView`: чья машина и кто платил
     поставщику, путь (ждёт машину, подготовка, готова; продажа — хвостом дорожки «Продажа», сделка со страховой — её путём
     там же), деньги, расходы с «+ Расход», чат с менеджером. Шторки — те же, что на странице машины на сайте. С 11.11.2026
     своей ветки для окна «Работы → Гаража» нет: окно машины одно (`CarView`), оно рисует те же дорожки. --}}
@php
    extract($garageView);
@endphp
<div class="flex flex-col gap-4">
    {{-- «⋯» справа своей колонкой: в узкой дорожке факты переносятся, а кнопка остаётся в первой строке. --}}
    <div class="flex items-start gap-3 text-sm">
        <div class="flex min-w-0 flex-1 flex-wrap items-center gap-x-3 gap-y-1.5 self-center">
            @if ($car->manager)<x-ui.person :user="$car->manager"/>@else<span class="text-ink-muted">взяли под себя</span>@endif
            @if ($car->deal?->garage_payer)<span class="text-ink-muted">{{ $car->deal->garagePayerLine() }}</span>@endif
        </div>
        @if ($morePrep)<span class="shrink-0">@include('garage.cars.actions', ['buttons' => [], 'more' => $morePrep, 'bar' => false])</span>@endif
    </div>
    @error('car')<x-ui.flash tone="danger">{{ $message }}</x-ui.flash>@enderror
    {{-- Машина на нашей парковке (18.10.2026): кто заберёт и пропуск — как у обычной сделки в «Получении». --}}
    @php $parked = $car->deal && $car->deal->state !== \App\Offers\DealState::Cancelled ? $offer->parkVehicle()->first() : null; @endphp
    @if ($parked && in_array($parked->state, [\App\Park\VehicleState::Stored, \App\Park\VehicleState::Expected], true) && ($car->deal->releasable() || $car->deal->passes()->exists()))
        <section>
            <div class="list-cap">Выдача с парковки</div>
            <x-deal.park-release :deal="$car->deal" :vehicle="$parked"/>
        </section>
    @endif
    @include('garage.cars.path', ['set' => 'prep', 'buttonsHere' => $prep])
    @if ($money && ! $car->isSold())@include('garage.cars.money')@endif
    @include('garage.cars.costs')
    {{-- Чат с менеджером сделки — карточкой (`x-deal.chat-card`) у окна и страницы; здесь — только с другим менеджером. --}}
    @if ($car->manager && ($deal ?? null)?->buyer_id !== $car->manager_id)<x-chat.staff-line :offer="$offer" :user="$car->manager"/>@endif
    @include('garage.cars.sheets')
</div>
