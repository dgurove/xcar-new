{{-- Выдача с нашей парковки в CRM (18.10.2026): где машина, кто заберёт (та же шторка, что у менеджера, — админ меняет за
     него) и что с пропуском: ждём, когда можно забирать, пропуск выдан, выдан с парковки. Внутри «Получения» сделки. --}}
@props(['deal', 'vehicle'])
@php
    $pass = $deal->passes()->where('vehicle_id', $vehicle->id)->whereNull('revoked_at')->latest('id')->first();
    $receiver = $deal->receiver();
    $sheet = 'receiver-crm-'.$deal->id;
    // Парковка погасила пропуск сама («Покупатель отказался») — новый только выбором в шторке.
    $revoked = $pass ? null : $deal->passes()->where('vehicle_id', $vehicle->id)->whereNotNull('revoked_at')
        ->where('revoke_reason', '!=', \App\Offers\Actions\SyncParkRelease::CHANGED)->latest('id')->first();
    $state = match (true) {
        (bool) $pass?->used_at => ['выдан '.$pass->used_at->translatedFormat('j M, H:i'), 'text-open'],
        (bool) $pass => ['пропуск выдан'.(($w = $pass->whenLabel('j M')) ? ', приедет '.$w : ''), 'text-open'],
        (bool) $revoked => ['пропуск погашен парковкой'.($revoked->revoke_reason ? ': '.mb_lcfirst($revoked->revoke_reason) : ''), 'text-urgent'],
        $deal->releasable() => ['пропуск ещё не выдан', 'text-ink-muted'],
        default => [$deal->pickupHold() ?? 'когда поставщик согласует', 'text-ink-muted'],
    };
@endphp
<div class="list mt-3">
    @if ($vehicle->yard)<x-pickup.yard :yard="$vehicle->yard"/>@else<div class="row text-ink-muted">Едет на нашу парковку</div>@endif
    @if ($deal->isActive() && ! $pass?->used_at)
        <button type="button" class="row justify-between text-left" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $sheet }}:open">
            <span class="min-w-0"><span class="block">Заберёт {{ $receiver?->labelFor(null) ?? '—' }}</span><span class="block text-sm {{ $state[1] }}">{{ $state[0] }}</span></span>
            <x-ui.icon name="chevron-right" class="size-4 shrink-0 text-ink-dim"/>
        </button>
    @else
        <div class="row"><span class="min-w-0"><span class="block">{{ $pass?->name ?? $receiver?->name }}</span><span class="block text-sm {{ $state[1] }}">{{ $state[0] }}</span></span></div>
    @endif
</div>
@if ($deal->isActive() && ! $pass?->used_at)
    <x-deal.receiver-sheet :deal="$deal" :receiver="$receiver" :id="$sheet" :action="'/work/deals/'.$deal->id.'/receiver'"/>
@endif
