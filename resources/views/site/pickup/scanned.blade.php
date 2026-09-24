{{-- QR пропуска отсканировали обычной камерой: объясняем, что это и куда ехать. Имя — сокращённо, телефона и почты
     нет: страницу откроет любой, у кого в руках код. --}}
@php [$status, $tone] = $pass?->status() ?? ['Код не найден', 'danger']; @endphp
<x-ui.auth :title="$pass ? 'Пропуск на получение ТС' : 'Код не найден'">
    @if ($pass)
        <p class="mt-3 text-ink-muted">Покажите этот QR-код сотруднику парковки: по нему выдают ТС</p>
        <div class="mt-4 flex items-center gap-2 text-sm {{ $tone === 'urgent' ? 'text-urgent' : ($tone === 'danger' ? 'text-danger' : 'text-ink-muted') }}">
            <span class="size-2 shrink-0 rounded-full {{ ['open' => 'bg-accent', 'urgent' => 'bg-urgent', 'danger' => 'bg-danger', 'closed' => 'bg-ink-dim'][$tone] }}"></span>{{ $status }}
        </div>
        @include('site.pickup.vehicle', ['vehicle' => $pass->vehicle])
        <dl class="mt-4 divide-y divide-line/60 text-sm">
            <div class="flex justify-between gap-4 py-2.5"><dt class="text-ink-muted">Когда заберут</dt><dd class="text-right">{{ $pass->pickup_on->translatedFormat('j F, D') }}</dd></div>
            <div class="flex justify-between gap-4 py-2.5"><dt class="text-ink-muted">Получатель</dt><dd class="text-right">{{ $pass->shortName() }}</dd></div>
        </dl>
    @else
        <p class="mt-3 text-ink-muted">Такого пропуска нет. Проверьте ссылку из письма</p>
    @endif
</x-ui.auth>
