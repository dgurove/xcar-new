{{-- Строка «Работы → Вывоза»: название в два этажа (под ним — шаг вывоза словом и дни, на телефоне ещё вендор с
     убытком), от 640 вендор столбцом, справа — куда везём. --}}
@props(['offer'])
@php
    use App\Offers\PickupState;
    use App\Support\Plural;
    [$word, $tone] = PickupState::of($offer);
    $position = $offer->position(\App\Workflow\Track::Service);
    // До «Вывоза и осмотра» — блок словом: «автомобиль у владельца», «вывоз автомобиля».
    if ($tone === 'plain') $word = mb_strtolower($position->stage->block?->name ?? $word);
    $color = match ($tone) { 'urgent' => 'text-urgent', 'open' => 'text-accent-text', default => 'text-ink-muted' };
    $days = PickupState::days($offer);
    $ref = trim(($offer->vendor?->name ?? '').' '.($offer->claim_ref ?? ''));
@endphp
<tr data-detail-key="{{ $offer->number }}" id="pickup-{{ $offer->number }}">
    <td class="grow">
        <x-ui.row-link :key="$offer->number"><span class="cell-title">{{ $offer->titleWithYear() }}</span></x-ui.row-link>
        <span class="cell-sub"><span class="{{ $color }}">{{ $word }}@if ($days), {{ $days }} {{ Plural::of($days, ['день', 'дня', 'дней']) }}@endif</span>@if ($ref)<span class="sm:hidden">{{ $ref }}</span>@endif</span>
    </td>
    <td class="cell-dim hidden sm:table-cell"><span class="block max-w-56 truncate">{{ $ref }}</span></td>
    <td class="num text-sm text-ink-muted">{{ mb_strtolower($offer->pickupDestination()->label()) }}</td>
</tr>
