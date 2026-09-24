{{-- Пропуск покупателя: состояние полосой над QR, QR крупно на белом и код группами; ниже списком — когда, где
     (с «Маршрут») и кто. Это же покупатель видит сразу после анкеты — можно сделать скриншот. QR у неподтверждённого
     не глушится: страховая может подтвердить устно прямо на парковке. Выдан — галка и время вместо QR. --}}
@php
    [$status, $tone] = $pass->status();
    $code = $vehicle->pickup_code;
    $icon = ['open' => 'check-circle', 'urgent' => 'refresh', 'closed' => 'check-circle', 'danger' => 'x'][$tone];
@endphp
<x-pickup.layout title="Пропуск">
    <x-pickup.title :vehicle="$vehicle" eyebrow="Пропуск на получение"/>
    <div class="pass-card mt-4">
        <div class="pass-state pass-state--{{ $tone }}"><x-ui.icon :name="$icon" class="size-5"/>{{ $status }}</div>
        @if ($pass->isLive())
            <div class="pass-qr">{!! $qr !!}</div>
            <div class="nums pb-4 text-center text-sm tracking-[.2em] text-ink-muted">{{ trim(chunk_split($pass->code, 5, ' ')) }}</div>
        @else
            <div class="pass-done"><span class="pass-done-icon"><x-ui.icon name="check" class="size-8"/></span>{{ $pass->used_at?->translatedFormat('j F, H:i') }}</div>
        @endif
    </div>
    <div class="list mt-4">
        <div class="pass-row"><span class="text-ink-muted">Когда</span><span>{{ \Illuminate\Support\Str::ucfirst($pass->pickup_on->translatedFormat('l, j F')) }}</span></div>
        <x-pickup.yard :yard="$vehicle->yard"/>
        <div class="pass-row"><span class="text-ink-muted">Получатель</span><span class="text-right">{{ $pass->name }}</span></div>
    </div>
    @if ($pass->isLive())
        <div class="list mt-4">
            <a href="/pickup/{{ $code }}?edit=1" class="pass-row"><span>{{ $pass->isConfirmed() ? 'Изменить дату' : 'Изменить данные или дату' }}</span><x-ui.icon name="chevron-right" class="size-4 text-ink-dim"/></a>
            <form method="post" action="/pickup/{{ $code }}/resend">@csrf<button type="submit" class="pass-row w-full text-left"><span class="min-w-0"><span class="block">Отправить на почту ещё раз</span><span class="block truncate text-sm text-ink-muted">{{ $pass->email }}</span></span><x-ui.icon name="send" class="size-4 shrink-0 text-ink-dim"/></button></form>
        </div>
    @endif
</x-pickup.layout>
