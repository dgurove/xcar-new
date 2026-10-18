{{-- Забрать с нашей парковки (18.10.2026, владелец): машина стоит у нас или едет к нам — вместо «От поставщика» с
     контактом страхователя наше: парковка с маршрутом, управляющий со звонком, «Заберёт» — кто приедет (шторка
     `x-deal.receiver-sheet`), и пропуск — QR, по которому выдадут на воротах (`SyncParkRelease`). Пропуск получателю —
     «Отправить» ссылкой `/pass/{code}`: он откроет QR у себя. Одно на страницу сделки и машину в гараже. --}}
@php
    /** @var \App\Offers\Handover $handover */
    $vehicle = $handover->park;
    $pass = $vehicle->passes()->where('deal_id', $deal->id)->whereNull('revoked_at')->latest('id')->first();
    $receiver = $deal->receiver();
    $stored = $vehicle->state === \App\Park\VehicleState::Stored;
    $keeper = $stored ? \App\Offers\Handover::parkContact($vehicle) : null;
    // Парковка погасила пропуск сама (покупатель отказался, выдача снята) — сказать почему: новый — выбором «Заберёт».
    $revoked = $pass ? null : $vehicle->passes()->where('deal_id', $deal->id)->whereNotNull('revoked_at')
        ->where('revoke_reason', '!=', \App\Offers\Actions\SyncParkRelease::CHANGED)->latest('id')->first();
    $sheet = 'receiver-'.$deal->id;
    $url = $pass ? \App\Support\Surface::Site->url('/pass/'.$pass->code) : null;
@endphp
<section class="mt-4 flex flex-col gap-4" aria-label="Забрать с парковки">
    <div class="list">
        @if ($vehicle->yard)
            <x-pickup.yard :yard="$vehicle->yard"/>
        @else
            <div class="row"><span class="min-w-0"><span class="block">Едет на нашу парковку</span><span class="block text-sm text-ink-muted">Адрес будет, когда её примут</span></span></div>
        @endif
        @if ($keeper)<x-ui.person-row :user="$keeper" :size="36" class="row"/>@endif
        {{-- Ещё едет к нам — пока она у владельца: не довезут, забирать у него. --}}
        @if (! $stored && ($handover->name || $handover->phone))
            <div class="row justify-between">
                <span class="min-w-0"><span class="block truncate">{{ $handover->name ?? 'Владелец' }}</span><span class="block text-sm text-ink-muted">пока у владельца</span></span>
                @if ($handover->phone)<a href="tel:+{{ $handover->phone }}" class="btn btn-round btn-quiet shrink-0" aria-label="Позвонить"><x-ui.icon name="phone" class="size-5"/></a>@endif
            </div>
        @endif
        @if ($deal->isActive() && ! $pass?->used_at)
            <button type="button" class="row justify-between text-left" data-controller="emit" data-action="emit#send" data-emit-event-param="{{ $sheet }}:open">
                <span class="text-ink-muted">Заберёт</span>
                <span class="flex min-w-0 items-center gap-1"><span class="truncate">{{ $receiver?->labelFor(auth()->user()) ?? 'Указать' }}{{ $receiver?->at ? ', '.$receiver->at->translatedFormat('j M, H:i') : '' }}</span><x-ui.icon name="chevron-right" class="size-4 shrink-0 text-ink-dim"/></span>
            </button>
        @endif
    </div>

    @if ($revoked)
        <p class="text-sm font-medium text-urgent">Парковка погасила пропуск{{ $revoked->revoke_reason ? ': '.mb_lcfirst($revoked->revoke_reason) : '' }}. Выберите, кто заберёт, выдадим новый</p>
    @elseif ($pass?->used_at)
        <p class="font-medium text-open">Выдан {{ $pass->used_at->translatedFormat('j M, H:i') }}</p>
    @elseif ($pass)
        <div class="pass-card">
            <div class="pass-state pass-state--open"><x-ui.icon name="check-circle" class="size-5"/>Пропуск: {{ $pass->name }}</div>
            <div class="pass-qr mx-auto max-w-64">{!! \App\Park\PassQr::svg($pass) !!}</div>
            <div class="nums pb-4 text-center text-sm tracking-[.2em] text-ink-muted">{{ trim(chunk_split($pass->code, 5, ' ')) }}</div>
        </div>
        <div class="acts acts-hero" data-controller="copy" data-copy-text-value="{{ $url }}" data-copy-title-value="Пропуск {{ $deal->offer->titleWithYear() }}" data-copy-done-value="Ссылка на пропуск в буфере">
            <textarea hidden data-copy-target="message">{{ 'Пропуск на получение '.$deal->offer->titleWithYear()."\n".($vehicle->yard ? 'Парковка «'.$vehicle->yard->name.'», '.$vehicle->yard->fullAddress()."\n" : '').'Покажите QR на воротах:' }}</textarea>
            <button type="button" class="act" data-action="copy#share" data-copy-target="share"><span class="btn btn-accent btn-round"><x-ui.icon name="share"/></span>Отправить</button>
            <button type="button" class="act" data-action="copy#copy"><span class="btn btn-quiet btn-round" data-copy-fallback><x-ui.icon name="copy"/></span>Скопировать</button>
        </div>
    @endif
</section>
@if ($deal->isActive() && ! $pass?->used_at)
    <x-deal.receiver-sheet :deal="$deal" :receiver="$receiver" :id="$sheet" :action="'/deals/'.$deal->id.'/receiver'"/>
@endif
