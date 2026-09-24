{{-- QR пропуска отсканировали обычной камерой: что это, куда ехать и когда. Имя — сокращённо, телефона и почты
     нет: страницу откроет любой, у кого в руках код. --}}
@php [$status, $tone] = $pass?->status() ?? ['Код не найден', 'danger']; @endphp
<x-pickup.layout :title="$pass ? 'Пропуск' : 'Код не найден'">
    @if ($pass)
        <div class="scan-lead">
            <span class="scan-lead-icon"><x-ui.icon name="qr" class="size-7"/></span>
            <div class="min-w-0 text-lg font-medium">Покажите QR сотруднику парковки</div>
        </div>
        <div class="mt-6"><x-pickup.title :vehicle="$pass->vehicle" eyebrow="Пропуск на получение"/></div>
        <div class="pass-state pass-state--{{ $tone }} mt-4 rounded-(--radius-l)"><x-ui.icon :name="['open' => 'check-circle', 'urgent' => 'refresh', 'closed' => 'check-circle', 'danger' => 'x'][$tone]" class="size-5"/>{{ $status }}</div>
        <div class="list mt-4">
            <div class="row justify-between"><span class="text-ink-muted">Когда</span><span>{{ \Illuminate\Support\Str::ucfirst($pass->pickup_on->translatedFormat('l, j F')) }}</span></div>
            <x-pickup.yard :yard="$pass->vehicle->yard"/>
            <div class="row justify-between"><span class="text-ink-muted">Получатель</span><span>{{ $pass->shortName() }}</span></div>
        </div>
    @else
        <div class="flex flex-1 flex-col items-center justify-center pb-16 text-center">
            <span class="scan-lead-icon scan-lead-icon--danger"><x-ui.icon name="x" class="size-7"/></span>
            <h1 class="mt-4 text-2xl">Код не найден</h1>
            <p class="mt-2 text-ink-muted">Проверьте ссылку из письма</p>
            <a href="/" class="btn btn-quiet mt-6">На xcar.ru</a>
        </div>
    @endif
</x-pickup.layout>
