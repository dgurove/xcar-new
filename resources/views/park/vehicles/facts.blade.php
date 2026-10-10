{{-- Факты карточки строки строками, как «Настройки» iOS (10.10.2026, владелец: «многие чипы можно текстом»): подпись слева,
     значение справа. Номер убытка и VIN копируются, парковка — шторка «Переставить», даты — календарь (админу), продажа —
     строкой (админу). Акты — в деле. Нужны переменные `park/vehicles/detail`. --}}
@php
    use App\Park\VehicleState;
    $state = $vehicle->state;
    $canMove = $vehicle->yard && $state === VehicleState::Stored && ! ($fromRequests ?? false);
    $days = $vehicle->daysStored();
@endphp
<div class="list mt-3">
    @if ($vehicle->ref)<div class="row justify-between gap-3"><span class="text-ink-muted">Номер убытка</span><x-ui.copy-code :value="$vehicle->ref" class="min-w-0 text-right"/></div>@endif
    @if ($vehicle->vin)<div class="row justify-between gap-3"><span class="text-ink-muted">VIN</span><x-ui.vin-code :vin="$vehicle->vin" class="min-w-0 text-right"/></div>@endif
    @if ($vehicle->vendor)<div class="row justify-between gap-3"><span class="text-ink-muted">Вендор</span><x-vendor.name :vendor="$vehicle->vendor" class="min-w-0 text-right"/></div>@endif
    @if ($canMove)
        <button type="button" class="row w-full justify-between gap-3 text-left" data-controller="emit" data-action="emit#send" data-emit-event-param="move:open"><span class="text-ink-muted">Парковка</span><span class="flex min-w-0 items-center gap-1"><span class="truncate">{{ $vehicle->yard->name }}{{ $vehicle->spot ? ', '.$vehicle->spot : '' }}</span><x-ui.chevron/></span></button>
    @elseif ($vehicle->yard)<div class="row justify-between gap-3"><span class="text-ink-muted">Парковка</span><span class="truncate">{{ $vehicle->yard->name }}</span></div>@endif
    @if ($total && $total['rate'] && $state !== VehicleState::Released)<div class="row justify-between gap-3"><span class="text-ink-muted">Ставка</span><span class="nums">{{ \App\Support\Money::rub($total['rate']) }}/сут</span></div>@endif
    @if ($debt > 0 && $state === VehicleState::Stored)<div class="row justify-between gap-3"><span class="text-ink-muted">Долг</span><span class="nums text-danger">{{ \App\Support\Money::rub($debt) }}</span></div>@endif
    @foreach (array_filter(['accepted_at' => $vehicle->accepted_at, 'released_at' => $state === VehicleState::Released ? $vehicle->released_at : null]) as $field => $at)
        <div class="row justify-between gap-3"><span class="text-ink-muted">{{ $field === 'accepted_at' ? 'Принята' : 'Выдана' }}</span>
            @if (auth()->user()->isParkAdmin())<x-park.date-edit :vehicle="$vehicle" :field="$field" class="nums underline decoration-dotted underline-offset-4"/>
            @else<span class="nums">{{ $at->translatedFormat($at->isCurrentYear() ? 'j M, H:i' : 'j M Y, H:i') }}</span>@endif
        </div>
    @endforeach
    @if ($state === VehicleState::Released && $days !== null)<div class="row justify-between gap-3"><span class="text-ink-muted">Стояла</span><span class="nums">{{ $days }} {{ \App\Support\Plural::of($days, ['день', 'дня', 'дней']) }}</span></div>@endif
    @if ($handedTo)<div class="row justify-between gap-3"><span class="text-ink-muted">Кому</span><span class="min-w-0 truncate text-right">{{ $handedTo.($released && ! empty($released->payload['pass']) ? ', по QR' : ($released && ! empty($released->payload['without_qr']) ? ', без QR' : '')) }}</span></div>@endif
    @unless ($fromRequests ?? false)<x-park.sale :vehicle="$vehicle" row/>@endunless
</div>
