@php use App\Park\{RequestState, ReleasedTo, Inspection}; use App\Support\Money; @endphp
{{-- Шаг «Хранение»: сколько стоит, продано ли; исходы — «Продано» (шторка) и «Выдать» (заявка на выдачу, форма spawn-form). --}}<div class="mt-2 flex flex-wrap items-center gap-1.5">
    @if ($vehicle->daysStored() !== null)<span class="chip nums">{{ $vehicle->daysStored() }} дн</span>@endif
    @if ($storageRate)<span class="chip nums">{{ $storageRate }}</span>@endif
    @if ($vehicle->sold_at)<span class="chip nums bg-urgent-soft text-urgent">продано {{ $vehicle->sold_at->translatedFormat('j M') }}</span>@endif
    @if ($vehicle->pickup_phone)<a href="tel:+{{ $vehicle->pickupPhoneDigits() }}" class="chip nums"><x-ui.icon name="phone" class="size-3.5"/>{{ $vehicle->pickup_name ? $vehicle->pickup_name.' ' : 'заберёт ' }}{{ $vehicle->pickup_phone }}</a>@elseif ($vehicle->pickup_name)<span class="chip">заберёт {{ $vehicle->pickup_name }}</span>@endif
    @if ($buyerFrom)<span class="chip nums {{ $buyerFrom->isPast() ? 'bg-danger-soft text-danger' : '' }}">покупатель с {{ $buyerFrom->translatedFormat('j M') }}, {{ Money::rub($buyerRate) }}/сут</span>@endif
</div>
@if ($canManage)
    <div class="mt-3 flex flex-wrap gap-2">
        <x-ui.button type="button" variant="secondary" size="sm" data-controller="emit" data-action="emit#send" data-emit-event-param="sold:open">{{ $vehicle->sold_at ? 'Кому выдать' : 'Продано' }}</x-ui.button>
        <x-ui.button variant="secondary" size="sm" form="spawn-form">Выдать</x-ui.button>
    </div>
@endif
