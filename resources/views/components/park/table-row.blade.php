{{-- Строка таблицы машин стоянки: машина с госномером, состояние точкой,
     стоянка, клиент, дней (у выданной — дата выдачи). Нажатие — окошко (peek). --}}
@props(['vehicle'])
@php
    use App\Park\VehicleState;
    $href = '/cars/'.$vehicle->id;
    $state = $vehicle->state;
@endphp
<tr id="vehicle-{{ $vehicle->id }}" data-peek-url="{{ $href }}/peek" data-href="{{ $href }}" tabindex="0">
    <td class="nums text-[11px] tracking-tighter text-ink-dim sm:text-[13px] sm:tracking-normal">{{ $vehicle->ref }}</td>
    <td class="grow">{{ $vehicle->titleWithYear() }}@if ($vehicle->plate) <span class="nums hidden text-ink-muted sm:inline">{{ $vehicle->plate }}</span>@endif</td>
    <td class="text-[11px] sm:text-[13px]"><span class="dot {{ match ($state->tone()) { 'open' => 'dot-open', 'urgent' => 'dot-urgent', default => '' } }}"></span>{{ $state->label() }}</td>
    <td class="hidden text-ink-dim sm:table-cell"><x-ui.place>{{ $vehicle->yard?->name }}</x-ui.place></td>
    <td class="hidden text-ink-dim sm:table-cell">{{ $vehicle->client?->name }}</td>
    <td class="num nums text-ink-dim">
        @if ($state === VehicleState::Released && $vehicle->released_at)<time datetime="{{ $vehicle->released_at->toIso8601String() }}">{{ $vehicle->released_at->translatedFormat('j M') }}</time>@elseif ($vehicle->daysStored() !== null){{ $vehicle->daysStored() }}@endif
    </td>
</tr>
