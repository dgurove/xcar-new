{{-- Строка таблицы ТС закупки на сайте: внешний номер (ref), название, тип, город, цена — своя у
     менеджера, лучшая у сотрудника, «Без цены». Нажатие — окошко (peek). --}}
@props(['car', 'purchase', 'query' => '', 'showKind' => false])
@php
    $staff = auth()->user()?->isStaff() ?? false;
    $mine = $staff ? null : $car->offerOf(auth()->user());
    $best = $staff ? $car->bestOffer() : null;
    $href = "/purchases/{$purchase->number}/{$car->ref}".($query ? '?'.$query : '');
@endphp
<tr id="car-{{ $car->id }}" data-peek-url="/purchases/{{ $purchase->number }}/{{ $car->ref }}/peek{{ $query ? '?'.$query : '' }}" data-href="{{ $href }}" tabindex="0">
    <td class="nums text-ink-dim">{{ $car->ref }}</td>
    <td class="grow">{{ $car->titleWithYear() }}</td>
    @if ($showKind)<td class="hidden text-ink-dim sm:table-cell">{{ $car->kind->label() }}</td>@endif
    <td class="hidden text-ink-dim sm:table-cell">{{ $car->settlement?->name ?? $car->city }}</td>
    <td class="num nums">
        @if ($staff)
            @if ($best)<span class="font-bold {{ $best->state === \App\Purchases\OfferState::Chosen ? 'text-accent-text' : '' }}">{{ \App\Support\Money::nums($best->amount) }}</span> <span class="hidden text-ink-dim sm:inline">{{ $best->user->shortName() }}</span>@endif
        @elseif ($mine)<span class="font-bold text-accent-text">{{ \App\Support\Money::nums($mine->amount) }}</span>
        @else<span class="text-ink-dim">Без цены</span>@endif
    </td>
</tr>
