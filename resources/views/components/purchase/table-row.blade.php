{{-- Строка таблицы машин закупки (CRM). Ячейка в два этажа: иконка типа и название, под ним ДЛ и то, что не так
     (флаг данных, «фото едут»; скрытая — просто тусклая строка, без слова); от 640 ДЛ, город и размещение встают столбцами. Тип — только иконкой: словом и
     столбцом он повторял её. Логотипа лизинговой перед ДЛ нет — у всей таблицы он один, это закупка одного вендора. Справа наша
     цена или лаймовое «оценить», под ней предложения менеджеров числом. Нажатие — карточка, в нём и оценка;
     data-todo — без нашей цены, по ним карточка идёт «Дальше». Ушедшая в предложение — справа его номер. --}}
@props(['car', 'purchase', 'table'])
@php
    use App\Purchases\ImportState;
    use App\Support\Money;
    $n = $purchase->number;
    $offers = $car->activeOfferList();
    $best = $offers->max('amount');
    $flag = $car->specs_state->needsAttention() ? $car->specs_state->label() : ($car->photos_state->needsAttention() ? $car->photos_state->label() : null);
    $note = $flag ? mb_strtolower($flag) : (in_array($car->photos_state, [ImportState::Pending, ImportState::Running], true) ? 'фото едут' : null);
@endphp
<tr data-detail-key="{{ $car->ref }}" data-search-row id="car-{{ $car->id }}" class="{{ $car->is_published ? '' : 'text-ink-muted' }}" @if (!$car->price_final && !$car->offer) data-todo @endif>
    @foreach ($table->columns as $key => $col)
        @switch($key)
        @case('title')
    <td class="{{ $col->classes() }}">
        <x-ui.row-link :key="$car->ref"><span class="cell-title"><x-ui.cat-icon :category="$car->kind->category()"/>{{ $car->titleWithYear() }}</span></x-ui.row-link>
        <span class="cell-sub">
            @if ($note)<span class="{{ $flag ? 'text-urgent' : '' }}">{{ $note }}</span>@endif
            <span class="sm:hidden">{{ $car->dl }}</span>
        </span>
    </td>
        @break
        @case('dl')
    <td class="{{ $col->classes() }}">{{ $car->dl }}</td>
        @break
        @case('city')
    <td class="{{ $col->classes() }}">@if ($city = $car->settlement?->name ?? $car->city)<x-ui.place class="text-ink-muted">{{ $city }}</x-ui.place>@endif</td>
        @break
        @case('listing')
    <td class="{{ $col->classes() }}">{{ $car->price_listing ? Money::nums($car->price_listing) : '' }}</td>
        @break
        @case('offers')
    <td class="{{ $col->classes() }}">@if ($offers->isNotEmpty()){{ $offers->count() }} <span class="ml-1 text-sm text-ink-muted">до {{ Money::nums($best) }}</span>@endif</td>
        @break
        @case('final')
    <td class="{{ $col->classes() }}">
        @if ($car->offer)<span class="text-accent-text">№ {{ $car->offer?->number }}</span>@elseif ($car->price_final){{ Money::nums($car->price_final) }}@else<span class="text-accent-text">оценить</span>@endif
        @if ($offers->isNotEmpty())<span class="cell-sub sm:hidden">{{ $offers->count() }} предл.</span>@endif
    </td>
        @break
        @endswitch
    @endforeach
</tr>
