{{-- Строка таблицы машин закупки (CRM). Ячейка в два этажа: название, под ним ДЛ, тип и то, что не так
     (флаг данных, «фото едут», «скрыта»); от 640 ДЛ, тип, размещение и флаг встают столбцами. Справа наша
     цена или лаймовое «оценить», под ней предложения менеджеров числом. Нажатие — окошко, в нём и оценка;
     data-unpriced — без нашей цены, по ним окошко идёт «Дальше». --}}
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
<tr id="car-{{ $car->id }}" data-peek-url="{{ $href }}/peek" data-href="{{ $href }}" tabindex="0" class="{{ $car->is_published ? '' : 'text-ink-muted' }}" @if (!$car->price_final) data-unpriced @endif>
    <td class="grow">
        <span class="cell-title">{{ $car->titleWithYear() }}</span>
        <span class="cell-sub">
            @if ($note)<span class="{{ $flag ? 'text-urgent' : '' }}">{{ $note }}</span>@endif
            <span class="sm:hidden">{{ $car->dl }}</span>
            <span class="sm:hidden">{{ mb_strtolower($car->kind->label()) }}</span>
        </span>
    </td>
    <td class="cell-dim hidden sm:table-cell">{{ $car->dl }}</td>
    <td class="cell-dim col-peek-hide hidden lg:table-cell">{{ $car->kind->label() }}</td>
    <td class="cell-dim num nums hidden sm:table-cell">{{ $car->price_listing ? Money::nums($car->price_listing) : '' }}</td>
    <td class="num nums hidden sm:table-cell">@if ($offers->isNotEmpty()){{ $offers->count() }} <span class="ml-1 text-sm text-ink-muted">до {{ Money::nums($best) }}</span>@endif</td>
    <td class="num nums">
        @if ($car->price_final){{ Money::nums($car->price_final) }}@else<span class="text-accent-text">оценить</span>@endif
        @if ($offers->isNotEmpty())<span class="cell-sub sm:hidden">{{ $offers->count() }} предл.</span>@endif
    </td>
</tr>
