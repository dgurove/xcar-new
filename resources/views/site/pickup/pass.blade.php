{{-- Пропуск покупателя: QR крупно на белом, над ним состояние, под ним ТС, парковка с картой, дата и почта, куда
     отправили. Это же покупатель видит сразу после анкеты — можно сделать скриншот. --}}
@php
    [$status, $tone] = $pass->status();
    $dot = ['open' => 'bg-accent', 'urgent' => 'bg-urgent', 'danger' => 'bg-danger', 'closed' => 'bg-ink-dim'][$tone];
    $code = $vehicle->pickup_code;
@endphp
<x-ui.auth title="Пропуск на получение ТС">
    <div class="mt-3 flex items-center gap-2 text-sm {{ $tone === 'urgent' ? 'text-urgent' : ($tone === 'danger' ? 'text-danger' : 'text-ink-muted') }}">
        <span class="size-2 shrink-0 rounded-full {{ $dot }}"></span>{{ $status }}
    </div>
    @if ($pass->isLive())
        <div class="mx-auto mt-5 w-full max-w-72 rounded-(--radius-l) bg-white p-3 shadow-[0_0_0_1px_rgb(0_0_0/.06)]">
            <div class="pickup-qr aspect-square w-full [&>svg]:h-full [&>svg]:w-full">{!! $qr !!}</div>
        </div>
        <div class="nums mt-2 text-center text-sm tracking-widest text-ink-muted">{{ trim(chunk_split($pass->code, 5, ' ')) }}</div>
        <p class="mt-4 text-center text-sm text-ink-muted">Покажите код сотруднику парковки</p>
    @endif
    @include('site.pickup.vehicle', ['vehicle' => $vehicle])
    <dl class="mt-4 divide-y divide-line/60 text-sm">
        <div class="flex justify-between gap-4 py-2.5"><dt class="text-ink-muted">Когда заберёте</dt><dd class="text-right">{{ $pass->pickup_on->translatedFormat('j F, D') }}</dd></div>
        <div class="flex justify-between gap-4 py-2.5"><dt class="text-ink-muted">Получатель</dt><dd class="text-right">{{ $pass->name }}</dd></div>
        <div class="flex justify-between gap-4 py-2.5"><dt class="text-ink-muted">QR на почте</dt><dd class="min-w-0 truncate text-right">{{ $pass->email }}</dd></div>
    </dl>
    @if ($pass->isLive())
        <div class="mt-5 space-y-2">
            <a href="/pickup/{{ $code }}?edit=1" class="btn btn-quiet w-full">{{ $pass->isConfirmed() ? 'Изменить дату' : 'Изменить данные или дату' }}</a>
            <form method="post" action="/pickup/{{ $code }}/resend">@csrf<button type="submit" class="btn btn-ghost w-full">Отправить на почту ещё раз</button></form>
        </div>
    @endif
</x-ui.auth>
