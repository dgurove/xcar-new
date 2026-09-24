{{-- Строка таблицы «Наличия». Ячейка ТС в два этажа: название, под ним тусклым предупреждения словом, госномер
     и номер убытка; от 640 второй этаж встаёт в линию за названием, а номер убытка уходит в свой столбец.
     Справа сутки со светофором простоя и набежавшая сумма; ставка на телефоне под суммой, на ПК своим
     столбцом. Парковки в строке нет — она заголовком группы; place — список без групп (поиск), тогда
     парковку пишем словом. Вендор — логотипом за названием, пока его столбца не видно (до 1024 и при открытом
     окошке). Нажатие — окошко (peek). --}}
@props(['vehicle', 'total' => null, 'debt' => 0, 'place' => false])
@php
    use App\Park\{Idle, VehicleState};
    use App\Support\Money;
    $href = '/cars/'.$vehicle->id;
    $state = $vehicle->state;
    $days = $vehicle->daysStored();
    $rate = $total['rate'] ?? null;
    $amount = $total['amount'] ?? 0;
@endphp
<tr id="vehicle-{{ $vehicle->id }}" data-peek-url="{{ $href }}/peek" data-href="{{ $href }}" tabindex="0">
    <td class="grow">
        <span class="cell-title">{{ $vehicle->titleWithYear() }}@if ($vehicle->vendor) <x-vendor.logo :vendor="$vehicle->vendor" class="col-peek-show"/>@endif</span>
        <span class="cell-sub">
            @if ($state !== VehicleState::Stored)<span>{{ mb_strtolower($state->label()) }}</span>@endif
            <x-park.alerts :vehicle="$vehicle" plain :place="$place"/>
            @if ($vehicle->plate)<span>{{ $vehicle->plate }}</span>@endif
            @if ($vehicle->ref)<span class="sm:hidden">{{ $vehicle->ref }}</span>@endif
            @if ($place && $vehicle->yard)<span>{{ $vehicle->yard->name }}</span>@endif
        </span>
    </td>
    <td class="cell-dim hidden sm:table-cell">@if ($vehicle->ref)<x-ui.copy-code :value="$vehicle->ref"/>@endif</td>
    <td class="cell-dim col-peek-hide hidden lg:table-cell">@if ($vehicle->vendor)<x-vendor.name :vendor="$vehicle->vendor" class="max-w-44"/>@endif</td>
    <td class="num nums">
        @if ($state === VehicleState::Released && $vehicle->released_at)
            <time class="text-ink-muted" datetime="{{ $vehicle->released_at->toIso8601String() }}">{{ $vehicle->released_at->translatedFormat('j M') }}</time>
        @elseif ($days !== null)
            <span class="{{ match (Idle::tone($days)) { 'danger' => 'text-danger', 'urgent' => 'text-urgent', default => '' } }}">{{ $days }}</span>
        @endif
    </td>
    <td class="cell-dim num nums hidden sm:table-cell">@if ($rate){{ Money::nums($rate) }}@endif</td>
    <td class="num nums">
        @if ($amount > 0)<span class="block">{{ Money::nums($amount) }}</span>@endif
        @if ($debt > 0)<span class="cell-sub text-danger">долг {{ Money::nums($debt) }}</span>
        @elseif ($rate)<span class="cell-sub sm:hidden">{{ Money::nums($rate) }}/д</span>@endif
    </td>
</tr>
