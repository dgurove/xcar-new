{{-- Строка таблицы ТС закупки на сайте. Ячейка в два этажа: название, под ним номер, тип и город; от 640 они
     встают столбцами. Справа цена — своя у менеджера (лаймом), лучшая у сотрудника с именем под ней, «Без цены».
     Нажатие — карточка. --}}
@props(['car', 'purchase', 'table', 'query' => ''])
@php $showKind = $table->has('kind'); @endphp
@php
    $staff = auth()->user()?->isAdmin() ?? false;
    $mine = $staff ? null : $car->offerOf(auth()->user());
    $best = $staff ? $car->bestOffer() : null;
    $city = $car->settlement?->title() ?? $car->city;
@endphp
<tr data-detail-key="{{ $car->ref }}" data-search-row id="car-{{ $car->id }}">
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('title')
    <td class="{{ $col->classes() }}">
        <x-ui.row-link :key="$car->ref"><span class="cell-title">{{ $car->titleWithYear() }}</span></x-ui.row-link>
        <span class="cell-sub">
            <span class="sm:hidden">№ {{ $car->ref }}</span>
            @if ($showKind)<span class="sm:hidden">{{ mb_strtolower($car->kind->label()) }}</span>@endif
            @if ($city)<span class="sm:hidden">{{ $city }}</span>@endif
        </span>
    </td>
        @break
        @case('number')
    <td class="{{ $col->classes() }}">{{ $car->ref }}</td>
        @break
        @case('kind')
    <td class="{{ $col->classes() }}">{{ $car->kind->label() }}</td>
        @break
        @case('city')
    <td class="{{ $col->classes() }}">{{ $city }}</td>
        @break
        @case('price')
    <td class="{{ $col->classes() }}">
        @if ($staff)
            @if ($best)<span class="{{ $best->state === \App\Purchases\OfferState::Chosen ? 'text-accent-text' : '' }}">{{ \App\Support\Money::nums($best->amount) }}</span><span class="cell-sub">{{ $best->user->shortName() }}</span>@endif
        @elseif ($mine)<span class="text-accent-text">{{ \App\Support\Money::nums($mine->amount) }}</span>
        @else<span class="text-ink-dim">Без цены</span>@endif
    </td>
        @break
        @endswitch
    @endforeach
</tr>
