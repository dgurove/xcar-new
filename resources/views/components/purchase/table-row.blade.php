{{-- Строка таблицы машин закупки (CRM). Ячейка в два этажа: иконка типа и название, под ним ДЛ и то, что не так
     (флаг данных, «фото едут», «скрыта»); от 640 ДЛ и размещение встают столбцами. Тип — только иконкой: словом и
     столбцом он повторял её. Логотипа лизинговой перед ДЛ нет — у всей таблицы он один, это закупка одного вендора. Справа наша
     цена или лаймовое «оценить», под ней предложения менеджеров числом. Нажатие — окошко, в нём и оценка;
     data-unpriced — без нашей цены, по ним окошко идёт «Дальше». Ушедшая в предложение — справа его номер. --}}
@props(['car', 'purchase'])
@php
    use App\Purchases\ImportState;
    use App\Support\Money;
    $n = $purchase->number;
    $href = "/purchases/{$n}/{$car->ref}";
    $offers = $car->activeOfferList();
    $best = $offers->max('amount');
    $flag = $car->specs_state->needsAttention() ? $car->specs_state->label() : ($car->photos_state->needsAttention() ? $car->photos_state->label() : null);
    $note = $flag ? mb_strtolower($flag) : (in_array($car->photos_state, [ImportState::Pending, ImportState::Running], true) ? 'фото едут' : (! $car->is_published ? 'скрыта' : null));
@endphp
<tr data-detail-key="{{ $car->ref }}" data-search-row id="car-{{ $car->id }}" class="{{ $car->is_published ? '' : 'text-ink-muted' }}" @if (!$car->price_final && !$car->offer_id) data-unpriced @endif>
    <td class="grow">
        <x-ui.row-link :key="$car->ref"><span class="cell-title"><x-ui.cat-icon :category="$car->kind->category()"/>{{ $car->titleWithYear() }}</span></x-ui.row-link>
        <span class="cell-sub">
            @if ($note)<span class="{{ $flag ? 'text-urgent' : '' }}">{{ $note }}</span>@endif
            <span class="sm:hidden">{{ $car->dl }}</span>
        </span>
    </td>
    <td class="cell-dim hidden sm:table-cell">{{ $car->dl }}</td>
    <td class="cell-dim num nums hidden sm:table-cell">{{ $car->price_listing ? Money::nums($car->price_listing) : '' }}</td>
    <td class="num nums hidden sm:table-cell">@if ($offers->isNotEmpty()){{ $offers->count() }} <span class="ml-1 text-sm text-ink-muted">до {{ Money::nums($best) }}</span>@endif</td>
    <td class="num nums">
        @if ($car->offer_id)<span class="text-accent-text">№ {{ $car->offer?->number }}</span>@elseif ($car->price_final){{ Money::nums($car->price_final) }}@else<span class="text-accent-text">оценить</span>@endif
        @if ($offers->isNotEmpty())<span class="cell-sub sm:hidden">{{ $offers->count() }} предл.</span>@endif
    </td>
</tr>
