{{-- Строка таблицы «Наличия». Ячейка ТС в два этажа: название, под ним тусклым предупреждения словом, госномер
     и номер убытка; от 640 второй этаж встаёт в линию за названием, а номер убытка уходит в свой столбец.
     Справа сутки («216д», одним цветом: долгая стоянка — не беда) и набежавшая сумма; ставка на телефоне под суммой, на ПК своим
     столбцом. Парковки в строке нет — она заголовком группы; place — список без групп (поиск), тогда
     парковку пишем словом. Вендор — логотипом за названием, пока его столбца не видно (до 1024 и при открытом
     окошке). Нажатие — окошко (peek). --}}
@props(['vehicle', 'total' => null, 'debt' => 0, 'place' => false])
@php
    use App\Park\VehicleState;
    use App\Support\Money;
    $href = '/cars/'.$vehicle->id;
    $state = $vehicle->state;
    $days = $vehicle->daysStored();
    $rate = $total['rate'] ?? null;
    $amount = $total['amount'] ?? 0;
@endphp
<tr id="vehicle-{{ $vehicle->id }}" data-peek-url="{{ $href }}/peek" data-href="{{ $href }}" tabindex="0">
    <td class="grow">
        <span class="cell-title">@if ($vehicle->vendor)<x-vendor.logo :vendor="$vehicle->vendor" class="col-peek-show mr-[.4em]"/>@endif{{ $vehicle->titleWithYear() }}</span>
        <span class="cell-sub">
            @if ($state !== VehicleState::Stored)<span>{{ mb_strtolower($state->label()) }}</span>@endif
            <x-park.alerts :vehicle="$vehicle" plain :place="$place"/>
            @if ($vehicle->plate)<x-ui.plate :value="$vehicle->plate"/>@endif
            @if ($vehicle->ref)<span class="sm:hidden">{{ $vehicle->ref }}</span>@endif
            @if ($place && $vehicle->yard)<span>{{ $vehicle->yard->name }}</span>@endif
        </span>
    </td>
    <td class="hidden text-sm sm:table-cell">@if ($vehicle->ref)<x-ui.copy-code :value="$vehicle->ref"/>@endif</td>
    <td class="col-peek-hide hidden text-sm lg:table-cell">@if ($vehicle->vendor)<x-vendor.name :vendor="$vehicle->vendor" class="max-w-44"/>@endif</td>
    <td class="num nums text-sm">
        @if ($state === VehicleState::Released && $vehicle->released_at)
            <time class="text-ink-muted" datetime="{{ $vehicle->released_at->toIso8601String() }}">{{ $vehicle->released_at->translatedFormat('j M') }}</time>
        @elseif ($days !== null)
            <span>{{ $days }}д</span>
        @endif
    </td>
    <td class="num nums hidden text-sm sm:table-cell">@if ($rate){{ Money::rub($rate) }}/д@endif</td>
    <td class="num nums text-sm">
        @if ($amount > 0)<span class="block">{{ Money::rub($amount) }}</span>@endif
        @if ($debt > 0)<span class="cell-sub text-danger">долг {{ Money::nums($debt) }}</span>
        @elseif ($rate)<span class="cell-sub sm:hidden">{{ Money::rub($rate) }}/д</span>@endif
    </td>
</tr>
