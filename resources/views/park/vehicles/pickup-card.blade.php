{{-- «Ссылка на анкету» (вендор с выдачей по QR, ТС стоит): ссылка — нажатие копирует, справа состояние одним тегом
     (анкета заполнена, подтверждён — по пропуску; иначе отправлена ли страховой). «Отправить страховой» — письмо
     `pickup-link` в переписку ТС; «Отключить и выдать новую» — пропуск гаснет, прежняя ссылка перестаёт открываться. --}}
@php
    $link = $vehicle->pickupLink();
    $sentAt = $vehicle->events->where('type', \App\Park\EventType::PickupLinkSent)->max('created_at');
    [$state, $tone] = match (true) {
        (bool) $pass => $pass->status(),
        (bool) $sentAt => ['отправлена '.$sentAt->translatedFormat('j M'), 'plain'],
        default => ['не отправлена', 'urgent'],
    };
@endphp
<x-ui.card title="Ссылка на анкету">
    <div class="list">
        @if ($link)
            <button type="button" class="row w-full justify-between text-left" data-controller="copy" data-copy-text-value="{{ $link }}" data-copy-done-value="Ссылка в буфере" data-action="copy#copy:prevent">
                <span class="min-w-0"><span class="block truncate">{{ preg_replace('~^https?://~', '', $link) }}</span><x-ui.state :tone="$tone" class="mt-0.5 block">{{ $state }}</x-ui.state></span>
                <x-ui.icon name="copy" class="size-5 shrink-0 text-ink-dim"/>
            </button>
        @elseif ($canManage)
            <form method="post" action="/cars/{{ $vehicle->id }}/pickup-link/create">@csrf<button type="submit" class="row w-full justify-between text-left"><span>Создать ссылку</span><x-ui.icon name="plus" class="size-5 shrink-0 text-ink-dim"/></button></form>
        @endif
        {{-- Отправить страховой — и приёмщику (27.10.2026); создать и отключить ссылку — управляющему. --}}
        @if ($handles ?? $canManage)
            <form method="post" action="/cars/{{ $vehicle->id }}/pickup-link" data-turbo-confirm="Отправить страховой ссылку для покупателя?">@csrf<button type="submit" class="row w-full justify-between text-left"><span>{{ $sentAt ? 'Отправить страховой ещё раз' : 'Отправить страховой' }}</span><x-ui.icon name="send" class="size-5 shrink-0 text-ink-dim"/></button></form>
            @if ($link && $canManage)
                <form method="post" action="/cars/{{ $vehicle->id }}/pickup-link/renew" data-turbo-confirm="Отключить эту ссылку? По ней анкета больше не откроется{{ $pass?->isLive() ? ', пропуск '.$pass->name.' погаснет' : '' }}">@csrf<button type="submit" class="row w-full justify-between text-left text-danger"><span>Отключить и выдать новую</span><x-ui.icon name="refresh" class="size-5 shrink-0"/></button></form>
            @endif
        @endif
    </div>
</x-ui.card>
