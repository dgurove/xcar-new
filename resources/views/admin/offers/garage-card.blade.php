{{-- Карточка «Гараж» в редакторе и карточке строки «Работы → Гаража» (`GarageView`): у кого, этап и дни, путь,
     деньги с закупочной, расходы и все действия сотрудника — те же шторки и адреса, что на странице машины на сайте. --}}
@php
    extract($garageView);
    // На «Ждёт страховую» машину ведёт маршрут сделки — его текущий шаг с кнопками тут же (в «Работе → Гараж»; в
    // редакторе он своей карточкой «Продажа» рядом).
    $salePosition = ($routeHere ?? false) && $car->isWaiting() ? $offer->position(\App\Workflow\Track::Sale) : null;
    $pickup = $offer->position(\App\Workflow\Track::Service);
@endphp
<div class="flex flex-col gap-4">
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-sm">
        <x-ui.state :tone="$car->state->tone()">{{ mb_strtolower($car->state->label()) }}</x-ui.state>
        @if ($car->manager)<x-ui.person :user="$car->manager"/>@else<span class="text-ink-muted">взяли под себя</span>@endif
        @if ($car->deal?->garage_payer)<span class="text-ink-muted">поставщику платит {{ mb_strtolower($car->deal->garage_payer->label()) }}</span>@endif
        @if ($pickup && ! $offer->pickedUp())<span class="text-ink-muted">вывоз: {{ $offer->evacuator?->shortName() ?? 'везём мы' }}, {{ mb_strtolower($pickup->stage->name) }}</span>@endif
        @if ($car->isWaiting() && $car->deal_id)<a href="/work/deals/{{ $car->deal_id }}" class="text-accent-text">Сделка ›</a>@endif
    </div>
    @error('car')<x-ui.flash tone="danger">{{ $message }}</x-ui.flash>@enderror
    @include('garage.cars.path')
    @if ($salePosition)
        <section>
            <div class="list-cap">Сделка</div>
            <x-route.path :offer="$offer" :position="$salePosition"/>
            @if ($errors->has('exit'))<p class="field-error mt-2">{{ $errors->first('exit') }}</p>@endif
        </section>
    @endif
    @if ($money)@include('garage.cars.money')@endif
    @include('garage.cars.costs')
    @if ($car->manager)<x-chat.staff-line :offer="$offer" :user="$car->manager"/>@endif
    @include('garage.cars.actions')
    @include('garage.cars.sheets')
</div>
