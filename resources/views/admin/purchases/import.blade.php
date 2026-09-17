<x-ui.shell :title="'Файл для закупки № '.$purchase->number" :back="['Закупка', '/purchases/'.$purchase->number]" narrow>
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
        @foreach ($stats as $label => $value)<x-ui.stat :value="$value" :label="$label"/>@endforeach
    </div>
    @php $bad = array_filter($rows, fn ($r) => $r['dl'] === null || $r['problems']); @endphp
    @if ($bad)
        <x-ui.card title="Не прочитаются" class="mt-4">
            <div class="flex flex-col gap-1 text-sm">@foreach (array_slice($bad, 0, 30) as $r)<div>Строка {{ $r['line'] }}: {{ implode('; ', $r['problems'] ?: ['пустой ДЛ']) }}</div>@endforeach</div>
        </x-ui.card>
    @endif
    <x-ui.card title="Первые строки" class="mt-4">
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-left text-ink-muted"><th class="py-1 pr-3">ДЛ</th><th class="py-1 pr-3">Марка, модель</th><th class="py-1 pr-3">Год</th><th class="py-1 pr-3">Цена</th><th class="py-1">Где</th></tr></thead><tbody>
            @foreach (array_slice($rows, 0, 15) as $r)<tr><td class="py-1 pr-3 tabular-nums">{{ $r['dl'] }}</td><td class="py-1 pr-3">{{ $r['brand'] }} {{ $r['model'] }}</td><td class="py-1 pr-3">{{ $r['year'] }}</td><td class="py-1 pr-3 tabular-nums">{{ $r['price_listing'] ? \App\Support\Money::nums($r['price_listing']) : '' }}</td><td class="py-1 truncate max-w-48"><x-ui.place>{{ $r['address'] }}</x-ui.place></td></tr>@endforeach
        </tbody></table></div>
    </x-ui.card>
    <form method="post" action="/purchases/{{ $purchase->number }}/import" class="action-bar">
        <div class="action-bar-inner">@csrf<input type="hidden" name="path" value="{{ $path }}">
        <x-ui.button class="min-w-0 flex-1">Завести ТС и забрать данные</x-ui.button></div>
    </form>
</x-ui.shell>
