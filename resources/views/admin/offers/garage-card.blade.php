{{-- Карточка «Гараж» в редакторе и карточке строки «Работы → Гаража» (`GarageView`): у кого, этап и дни, путь,
     деньги с закупочной, расходы и все действия сотрудника — те же шторки и адреса, что на странице машины на сайте. --}}
@php extract($garageView); @endphp
<div class="flex flex-col gap-4">
    <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-sm">
        <x-ui.state :tone="$car->state->tone()">{{ mb_strtolower($car->state->label()) }}</x-ui.state>
        @if ($car->manager)<x-ui.person :user="$car->manager"/>@else<span class="text-ink-muted">взяли под себя</span>@endif
        @if ($car->deal?->garage_payer)<span class="text-ink-muted">поставщику платит {{ mb_strtolower($car->deal->garage_payer->label()) }}</span>@endif
        @if ($car->isWaiting() && $car->deal_id)<a href="/work/deals/{{ $car->deal_id }}" class="text-accent-text">Сделка ›</a>@endif
    </div>
    @error('car')<x-ui.flash tone="danger">{{ $message }}</x-ui.flash>@enderror
    @include('garage.cars.path')
    @if ($money)@include('garage.cars.money')@endif
    @include('garage.cars.costs')
    @include('garage.cars.actions')
    @include('garage.cars.sheets')
</div>
