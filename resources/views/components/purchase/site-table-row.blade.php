{{-- Строка таблицы ТС закупки на сайте. Ячейка в два этажа: название, под ним номер, тип и город; от 640 они
     встают столбцами. Справа цена — своя у менеджера (лаймом), лучшая у сотрудника с именем под ней, «Без цены».
     Нажатие — окошко. --}}
@props(['car', 'purchase', 'query' => '', 'showKind' => false])
@php
    $staff = auth()->user()?->isStaff() ?? false;
    $mine = $staff ? null : $car->offerOf(auth()->user());
    $best = $staff ? $car->bestOffer() : null;
    $href = "/purchases/{$purchase->number}/{$car->ref}".($query ? '?'.$query : '');
    $city = $car->settlement?->name ?? $car->city;
@endphp
<tr id="car-{{ $car->id }}" data-peek-url="/purchases/{{ $purchase->number }}/{{ $car->ref }}/peek{{ $query ? '?'.$query : '' }}" data-href="{{ $href }}" tabindex="0">
    <td class="grow">
        <span class="cell-title">{{ $car->titleWithYear() }}</span>
        <span class="cell-sub">
            <span class="sm:hidden">№ {{ $car->ref }}</span>
            @if ($showKind)<span class="sm:hidden">{{ mb_strtolower($car->kind->label()) }}</span>@endif
            @if ($city)<span class="sm:hidden">{{ $city }}</span>@endif
        </span>
    </td>
    <td class="cell-dim nums hidden sm:table-cell">{{ $car->ref }}</td>
    @if ($showKind)<td class="cell-dim hidden sm:table-cell">{{ $car->kind->label() }}</td>@endif
    <td class="cell-dim col-peek-hide hidden sm:table-cell">{{ $city }}</td>
    <td class="num nums">
        @if ($staff)
            @if ($best)<span class="{{ $best->state === \App\Purchases\OfferState::Chosen ? 'text-accent-text' : '' }}">{{ \App\Support\Money::nums($best->amount) }}</span><span class="cell-sub">{{ $best->user->shortName() }}</span>@endif
        @elseif ($mine)<span class="text-accent-text">{{ \App\Support\Money::nums($mine->amount) }}</span>
        @else<span class="text-ink-dim">Без цены</span>@endif
    </td>
</tr>
