<x-ui.shell :title="'Файл для закупки № '.$purchase->number" :back="'/admin/zakupki/'.$purchase->number">
    <x-ui.card>
        <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
            @foreach ($stats as $label => $value)<div><dt class="text-ink-muted">{{ $label }}</dt><dd class="text-lg font-semibold tabular-nums">{{ $value }}</dd></div>@endforeach
        </dl>
    </x-ui.card>
    @php $bad = array_filter($rows, fn ($r) => $r['dl'] === null || $r['problems']); @endphp
    @if ($bad)
        <x-ui.card title="Не прочитаются" class="mt-4">
            <div class="flex flex-col gap-1 text-sm">@foreach (array_slice($bad, 0, 30) as $r)<div>Строка {{ $r['line'] }}: {{ implode('; ', $r['problems'] ?: ['пустой ДЛ']) }}</div>@endforeach</div>
        </x-ui.card>
    @endif
    <x-ui.card title="Первые строки" class="mt-4">
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-left text-ink-muted"><th class="py-1 pr-3">ДЛ</th><th class="py-1 pr-3">Машина</th><th class="py-1 pr-3">Год</th><th class="py-1 pr-3">Цена</th><th class="py-1">Где</th></tr></thead><tbody>
            @foreach (array_slice($rows, 0, 15) as $r)<tr><td class="py-1 pr-3 tabular-nums">{{ $r['dl'] }}</td><td class="py-1 pr-3">{{ $r['brand'] }} {{ $r['model'] }}</td><td class="py-1 pr-3">{{ $r['year'] }}</td><td class="py-1 pr-3 tabular-nums">{{ $r['price_listing'] ? number_format($r['price_listing'], 0, '', ' ') : '' }}</td><td class="py-1 truncate max-w-48">{{ $r['address'] }}</td></tr>@endforeach
        </tbody></table></div>
    </x-ui.card>
    <form method="post" action="/admin/zakupki/{{ $purchase->number }}/import" class="sticky-actions">
        @csrf<input type="hidden" name="path" value="{{ $path }}">
        <x-ui.button class="flex-1">Завести машины и забрать данные</x-ui.button>
    </form>
</x-ui.shell>
