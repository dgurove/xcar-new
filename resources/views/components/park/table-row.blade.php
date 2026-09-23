{{-- Строка таблицы «Наличия»: номер убытка, ТС с госномером и тегами «что не так», парковка, вендор, дней,
     ставка за сутки и набежавшая сумма. Столбца «Состояние» нет: у стоящих там было одно и то же, а у
     нестоящих состояние стоит тегом у названия. Нажатие — окошко (peek). --}}
@props(['vehicle', 'total' => null, 'debt' => 0])
@php
    use App\Park\VehicleState;
    use App\Support\Money;
    $href = '/cars/'.$vehicle->id;
    $state = $vehicle->state;
@endphp
<tr id="vehicle-{{ $vehicle->id }}" data-peek-url="{{ $href }}/peek" data-href="{{ $href }}" tabindex="0">
    <td class="nums text-[11px] tracking-tighter text-ink-dim sm:text-[13px] sm:tracking-normal">@if ($vehicle->ref)<x-ui.copy-code :value="$vehicle->ref"/>@endif</td>
    <td class="grow">{{ $vehicle->titleWithYear() }}@if ($vehicle->plate) <span class="nums hidden text-ink-muted sm:inline">{{ $vehicle->plate }}</span>@endif @if ($state !== VehicleState::Stored)<span class="tag">{{ $state->label() }}</span>@endif <x-park.alerts :vehicle="$vehicle"/></td>
    <td class="hidden text-ink-dim sm:table-cell"><x-ui.place>{{ $vehicle->yard?->name }}</x-ui.place></td>
    <td class="hidden text-ink-dim sm:table-cell">{{ $vehicle->vendor?->name }}</td>
    <td class="num nums text-ink-dim">
        @if ($state === VehicleState::Released && $vehicle->released_at)<time datetime="{{ $vehicle->released_at->toIso8601String() }}">{{ $vehicle->released_at->translatedFormat('j M') }}</time>@elseif ($vehicle->daysStored() !== null){{ $vehicle->daysStored() }}@endif
    </td>
    <td class="num nums text-ink-dim">@if ($total && $total['rate']){{ Money::nums($total['rate']) }}@endif</td>
    <td class="num nums">@if ($total && $total['amount'] > 0){{ Money::nums($total['amount']) }}@endif @if ($debt > 0)<span class="block text-danger">долг {{ Money::nums($debt) }}</span>@endif</td>
</tr>
