{{-- Дорожка «Гараж» редактора (06.10.2026: только подготовка) и карточка строки «Работы → Гаража» (`$routeHere`) из
     `GarageView`: чья машина и кто платил поставщику, путь (в редакторе — ждёт машину, подготовка, готова; продажа —
     хвостом дорожки «Продажа»; в карточке строки — весь путь с шагом сделки со страховой, пока она идёт), деньги,
     расходы с «+ Расход», чат с менеджером. Шторки — те же, что на странице машины на сайте. --}}
@php
    extract($garageView);
    $routeHere ??= false;
    $salePosition = $routeHere && $car->dealOpen() ? $offer->position(\App\Workflow\Track::Sale) : null;
@endphp
<div class="flex flex-col gap-4">
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-sm">
        @if ($car->manager)<x-ui.person :user="$car->manager"/>@else<span class="text-ink-muted">взяли под себя</span>@endif
        @if ($car->deal?->garage_payer)<span class="text-ink-muted">{{ $car->deal->garage_payer === \App\Garage\GaragePayer::Us ? 'поставщику платим мы' : 'поставщику платит менеджер' }}</span>@endif
        @if ($routeHere)<x-ui.state :tone="$car->state->tone()">{{ mb_strtolower($car->state->label()) }}</x-ui.state>@endif
        @php $menu = $routeHere ? $more : $morePrep; @endphp
        @if ($menu)<span class="ml-auto">@include('garage.cars.actions', ['buttons' => [], 'more' => $menu, 'bar' => false])</span>@endif
    </div>
    @error('car')<x-ui.flash tone="danger">{{ $message }}</x-ui.flash>@enderror
    @if ($salePosition)
        <section>
            <div class="list-cap">Сделка со страховой</div>
            <x-route.path :offer="$offer" :position="$salePosition"/>
            @if ($errors->has('exit'))<p class="field-error mt-2">{{ $errors->first('exit') }}</p>@endif
        </section>
    @endif
    @include('garage.cars.path', $routeHere ? ['set' => 'all', 'withDeal' => false, 'buttonsHere' => [...$prep, ...$sale]] : ['set' => 'prep', 'buttonsHere' => $prep])
    @if ($money && ($routeHere || ! $car->isSold()))@include('garage.cars.money')@endif
    @include('garage.cars.costs')
    @if ($car->manager)<x-chat.staff-line :offer="$offer" :user="$car->manager"/>@endif
    @include('garage.cars.sheets')
</div>
