{{-- Акты ТС чипами (`IntakeActs`): «приём» и «выдача» — по чипу на вид, остальные файлы вида (у Альфы акт в два листа, наш
     отчёт) — скрытыми ссылками той же строки, они вкладками в шторке; акта по имени нет — «документы N» тем же способом;
     ничего — «нет». --}}
@props(['acts'])
@php
    $groups = array_filter([
        'приём' => $acts['intake'],
        'выдача' => $acts['release'],
        'документы '.count($acts['other']) => $acts['intake'] ? [] : $acts['other'],
    ]);
@endphp
<span class="inline-flex flex-nowrap gap-1">
    @forelse ($groups as $label => $docs)
        @foreach ($docs as $n => $d)<x-ui.doc :doc="$d" :class="$n ? 'hidden' : 'chip !text-xs whitespace-nowrap'">{{ $label }}</x-ui.doc>@endforeach
    @empty
        <span class="text-sm text-ink-dim">нет</span>
    @endforelse
</span>
