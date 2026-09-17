{{-- Строка таблицы машин закупки (CRM): ДЛ, машина, тип, размещение, предложения
     менеджеров числом и лучшей, наша цена, флаги. Нажатие — окошко (peek). --}}
@props(['car', 'purchase'])
@php
    use App\Purchases\ImportState;
    $n = $purchase->number;
    $href = "/purchases/{$n}/{$car->ref}";
    $offers = $car->activeOfferList();
    $best = $offers->max('amount');
    $flag = $car->specs_state->needsAttention() ? $car->specs_state->label() : ($car->photos_state->needsAttention() ? $car->photos_state->label() : null);
@endphp
<tr id="car-{{ $car->id }}" data-peek-url="{{ $href }}/peek" data-href="{{ $href }}" tabindex="0" class="{{ $car->is_published ? '' : 'text-ink-muted' }}">
    <td class="nums text-ink-dim">{{ $car->dl }}</td>
    <td class="grow">{{ $car->titleWithYear() }}</td>
    <td class="hidden sm:table-cell">{{ $car->kind->label() }}</td>
    <td class="num nums hidden text-ink-dim sm:table-cell">{{ $car->price_listing ? \App\Support\Money::nums($car->price_listing) : '' }}</td>
    <td class="num nums">@if ($offers->isNotEmpty())<span class="text-ink">{{ $offers->count() }}</span> <span class="hidden text-ink-dim sm:inline">{{ \App\Support\Money::nums($best) }}</span>@endif</td>
    <td class="num nums">@if ($car->price_final)<span class="font-bold">{{ \App\Support\Money::nums($car->price_final) }}</span>@else<a href="{{ $href }}/estimate" class="text-accent-text">оценить</a>@endif</td>
    <td class="hidden sm:table-cell">
        @if ($flag)<span class="dot dot-urgent"></span>{{ $flag }}@elseif (in_array($car->photos_state, [ImportState::Pending, ImportState::Running], true))фото едут@elseif (!$car->is_published)скрыта@endif
    </td>
</tr>
